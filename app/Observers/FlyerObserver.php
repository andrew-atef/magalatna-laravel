<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FlyerStatus;
use App\Jobs\PingIndexNowJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FlyerObserver
{
    /**
     * Per-request/worker transient state map (keyed by flyer id).
     * ALWAYS unset() in saved()/deleted() so long-running queue workers
     * never leak memory across thousands of processed models.
     *
     * @var array<int, array{wasPublished: bool, oldRetailerId: int|null}>
     */
    private static array $stateBeforeUpdate = [];

    /**
     * R2 image paths captured during deleting() — BEFORE SQLite's
     * ON DELETE CASCADE wipes flyer_pages rows.
     *
     * @var array<int, list<string>>
     */
    private static array $pendingR2Paths = [];

    public function updating(Flyer $flyer): void
    {
        $rawStatus = $flyer->getRawOriginal('status');
        $wasPublished = $rawStatus instanceof FlyerStatus
            ? $rawStatus === FlyerStatus::Published
            : $rawStatus === FlyerStatus::Published->value;

        $oldRetailerId = $flyer->getOriginal('retailer_id');

        self::$stateBeforeUpdate[(int) $flyer->id] = [
            'wasPublished' => $wasPublished,
            'oldRetailerId' => is_numeric($oldRetailerId) ? (int) $oldRetailerId : null,
        ];
    }

    public function saved(Flyer $flyer): void
    {
        $before = self::$stateBeforeUpdate[(int) $flyer->id] ?? null;
        unset(self::$stateBeforeUpdate[(int) $flyer->id]);

        try {
            $wasPublished = $before['wasPublished'] ?? false;
            $isPublished = $flyer->status === FlyerStatus::Published;

            // Cover BOTH directions: publish transitions AND unpublish
            // transitions (Published -> Draft/Expired/PendingReview) — the
            // previously-public URL must be purged from the CDN edge and the
            // sitemap/llms caches refreshed.
            if (! $isPublished && ! $wasPublished) {
                return;
            }

            $relevantFields = [
                'status', 'title', 'slug', 'valid_from', 'valid_until',
                'retailer_id', 'bluf_summary', 'editorial_overview',
                'applicable_governorates', 'total_pages',
            ];

            if (! $flyer->wasRecentlyCreated && ! $flyer->wasChanged($relevantFields)) {
                return;
            }

            $this->handlePurge($flyer, $before);
        } catch (Throwable $e) {
            Log::error('FlyerObserver: saved event failure.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleting(Flyer $flyer): void
    {
        // Capture R2 paths BEFORE the delete: flyer_pages.flyer_id has
        // ON DELETE CASCADE, so by the time deleted() runs the rows are gone.
        // BOTH the master images, the cover thumbnails AND the flyer-level
        // Google Discover composite are separate R2 objects that must never
        // outlive their flyer.
        $imagePaths = $flyer->pages()
            ->whereNotNull('image_path')
            ->pluck('image_path');

        $thumbPaths = $flyer->pages()
            ->whereNotNull('thumb_path')
            ->pluck('thumb_path');

        $discoverPath = trim((string) ($flyer->discover_image_path ?? ''));

        self::$pendingR2Paths[(int) $flyer->id] = $imagePaths
            ->merge($thumbPaths)
            ->when($discoverPath !== '', static fn ($collection) => $collection->push($discoverPath))
            ->map(static fn (mixed $path): string => trim((string) $path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function deleted(Flyer $flyer): void
    {
        $paths = self::$pendingR2Paths[(int) $flyer->id] ?? [];
        unset(self::$pendingR2Paths[(int) $flyer->id], self::$stateBeforeUpdate[(int) $flyer->id]);

        try {
            self::forgetCachedSeoArtifacts();

            // Async R2 deletion — synchronous Storage::delete() here would do
            // network I/O against R2 inside (potentially) a SQLite transaction.
            if ($paths !== []) {
                dispatch(static function () use ($paths): void {
                    try {
                        Storage::disk('r2')->delete($paths);
                    } catch (Throwable $e) {
                        Log::warning('FlyerObserver: Bulk R2 delete failed.', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                })->afterCommit();
            }

            $urls = $this->collectUrls($flyer, null);
            $this->dispatchPurge($urls, 'deleted');
        } catch (Throwable $e) {
            Log::error('FlyerObserver: deleted event failure.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{wasPublished: bool, oldRetailerId: int|null}|null  $before
     */
    private function handlePurge(Flyer $flyer, ?array $before): void
    {
        try {
            self::forgetCachedSeoArtifacts();

            $urls = $this->collectUrls($flyer, $before);
            $this->dispatchPurge($urls, 'saved');

            // IndexNow ping on publish ONLY — batched into one HTTP call and
            // restricted to live, indexable pages: the offer, the retailer hub
            // and the homepage. NEVER /sitemap.xml or /llms.txt (not indexable)
            // and never for unpublish/delete events (those URLs are dead).
            if ($flyer->status === FlyerStatus::Published && (string) ($flyer->slug ?? '') !== '') {
                $indexable = [$this->flyerUrl($flyer)];

                $retailerSlug = $flyer->relationLoaded('retailer')
                    ? $flyer->retailer?->slug
                    : $flyer->retailer()->first(['slug'])?->slug;

                if (is_string($retailerSlug) && $retailerSlug !== '') {
                    $indexable[] = self::baseUrl().route('retailers.show', ['retailer' => $retailerSlug], false);
                }

                $indexable[] = self::baseUrl().'/';

                PingIndexNowJob::dispatch(array_values(array_unique(array_filter(array_map('strval', $indexable)))))
                    ->afterCommit();
            }
        } catch (Throwable $e) {
            Log::error('FlyerObserver: handlePurge failure.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Canonical offer URL from config('app.url') + relative route.
     */
    private function flyerUrl(Flyer $flyer): string
    {
        return self::baseUrl().route('flyers.show', ['slug' => (string) $flyer->slug], false);
    }

    /**
     * Sitemap/llms caches are pure DB-derived artifacts — invalidate them AFTER
     * the surrounding transaction commits (Cache::forget inside a live SQLite
     * transaction would just be re-read-locked / wasted if the tx rolls back).
     */
    private static function forgetCachedSeoArtifacts(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');
        });
    }

    /**
     * Build ALL purgeable URLs from config('app.url') + relative routes.
     * NEVER url('/') — CLI workers and admin hosts would leak 127.0.0.1/www.
     *
     * @param  array{wasPublished: bool, oldRetailerId: int|null}|null  $before
     * @return list<string>
     */
    private function collectUrls(Flyer $flyer, ?array $before): array
    {
        $base = self::baseUrl();
        $urls = [];
        $slug = (string) ($flyer->slug ?? '');

        if ($slug !== '') {
            $urls[] = $base.route('flyers.show', ['slug' => $slug], false);
        }

        // Current retailer hub (+ old hub when the flyer moved retailers).
        $retailerSlugs = [];

        if ($flyer->retailer_id !== null) {
            $retailer = $flyer->relationLoaded('retailer')
                ? $flyer->retailer
                : $flyer->retailer()->first(['slug']);

            if ($retailer !== null && (string) $retailer->slug !== '') {
                $retailerSlugs[] = (string) $retailer->slug;
            }
        }

        $oldRetailerId = $before['oldRetailerId'] ?? null;
        if ($oldRetailerId !== null && $oldRetailerId !== (int) $flyer->retailer_id) {
            $oldRetailerSlug = Retailer::query()->whereKey($oldRetailerId)->value('slug');
            if (is_string($oldRetailerSlug) && $oldRetailerSlug !== '') {
                $retailerSlugs[] = $oldRetailerSlug;
            }
        }

        foreach (array_unique($retailerSlugs) as $retailerSlug) {
            $urls[] = $base.route('retailers.show', ['retailer' => $retailerSlug], false);
        }

        // Cross-linking widgets on the flyer page render up to 4 sibling
        // flyers of the same retailer — their rendered URLs change too.
        if ($flyer->retailer_id !== null) {
            $siblingSlugs = Flyer::query()
                ->where('retailer_id', $flyer->retailer_id)
                ->whereKeyNot((int) ($flyer->getKey() ?? 0))
                ->where('status', FlyerStatus::Published)
                ->orderByDesc('valid_from')
                ->limit(4)
                ->pluck('slug');

            foreach ($siblingSlugs as $siblingSlug) {
                if ((string) $siblingSlug !== '') {
                    // DRY: resolve the path through the route definition — the
                    // /offers prefix must never drift from routes/web.php.
                    $urls[] = $base.route('flyers.show', ['slug' => (string) $siblingSlug], false);
                }
            }
        }

        $urls[] = $base.'/';
        $urls[] = $base.'/sitemap.xml';
        $urls[] = $base.'/llms.txt';

        return array_values(array_unique(array_filter(array_map('trim', $urls))));
    }

    /**
     * @param  list<string>  $urls
     */
    private function dispatchPurge(array $urls, string $event): void
    {
        if ($urls === []) {
            return;
        }

        try {
            PurgeCloudflareCacheJob::dispatch($urls)->afterCommit();
        } catch (Throwable $e) {
            Log::error('FlyerObserver: Failed to dispatch PurgeCloudflareCacheJob.', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Canonical host from config only — never request-bound.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Bulk publish for the admin panel: persist every flyer WITHOUT per-model
     * observer events, then dispatch ONE combined PurgeCloudflareCacheJob and
     * ONE PingIndexNowJob for the whole batch. Publishing N flyers one-by-one
     * would otherwise fire 2N external API jobs whose URL sets overlap almost
     * entirely (homepage, sitemap.xml, llms.txt, shared retailer hubs).
     *
     * Replicates saved()/handlePurge() semantics exactly:
     *  - sitemap/llms caches invalidated (after commit),
     *  - CDN purge with each flyer's full collectUrls() set (deduped),
     *  - IndexNow ping ONLY for real publish transitions, restricted to the
     *    flyer, its retailer hub and the homepage — never sitemap/llms.
     *
     * @param  iterable<int, Flyer>  $flyers
     * @return int number of flyers whose status actually transitioned
     */
    public static function bulkPublish(iterable $flyers): int
    {
        $published = 0;
        $purgeUrls = [];
        $pingUrls = [];
        $observer = new self();

        self::forgetCachedSeoArtifacts();

        foreach ($flyers as $flyer) {
            $flyer->status = FlyerStatus::Published;
            // saveQuietly() = no per-model events: no per-flyer purge/ping
            // jobs, no state-map entries in updating()/saved().
            $flyer->saveQuietly();

            // Same gate as saved(): an already-Published flyer whose status
            // did not change must not re-trigger purge/ping side effects.
            if (! $flyer->wasChanged('status')) {
                continue;
            }

            $published++;

            // Purge set identical to a single save of this flyer ($before is
            // null: bulk publish never moves retailers).
            $purgeUrls = array_merge($purgeUrls, $observer->collectUrls($flyer, null));

            if ((string) ($flyer->slug ?? '') !== '') {
                $pingUrls[] = $observer->flyerUrl($flyer);

                $retailerSlug = $flyer->relationLoaded('retailer')
                    ? $flyer->retailer?->slug
                    : $flyer->retailer()->first(['slug'])?->slug;

                if (is_string($retailerSlug) && $retailerSlug !== '') {
                    $pingUrls[] = self::baseUrl().route('retailers.show', ['retailer' => $retailerSlug], false);
                }
            }
        }

        if ($published === 0) {
            return 0;
        }

        $pingUrls[] = self::baseUrl().'/';

        $purgeUrls = array_values(array_unique(array_filter(array_map('strval', $purgeUrls))));
        $pingUrls = array_values(array_unique(array_filter(array_map('strval', $pingUrls))));

        try {
            PurgeCloudflareCacheJob::dispatch($purgeUrls)->afterCommit();
        } catch (Throwable $e) {
            Log::error('FlyerObserver: bulk purge dispatch failure.', [
                'count' => $published,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            PingIndexNowJob::dispatch($pingUrls)->afterCommit();
        } catch (Throwable $e) {
            Log::error('FlyerObserver: bulk IndexNow dispatch failure.', [
                'count' => $published,
                'error' => $e->getMessage(),
            ]);
        }

        return $published;
    }
}
