<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RetailerObserver
{
    /**
     * Transient state map (keyed by retailer id) — ALWAYS unset() in
     * saved()/deleted() to avoid memory leaks in long-running workers.
     *
     * @var array<int, array{oldSlug: string, wasActive: bool}>
     */
    private static array $stateBeforeUpdate = [];

    public function updating(Retailer $retailer): void
    {
        self::$stateBeforeUpdate[(int) $retailer->id] = [
            'oldSlug' => (string) $retailer->getOriginal('slug'),
            'wasActive' => (bool) $retailer->getOriginal('is_active'),
        ];
    }

    public function saved(Retailer $retailer): void
    {
        $before = self::$stateBeforeUpdate[(int) $retailer->id] ?? null;
        unset(self::$stateBeforeUpdate[(int) $retailer->id]);

        try {
            $relevantFields = ['name', 'slug', 'logo_path', 'is_active'];

            if (! $retailer->wasRecentlyCreated && ! $retailer->wasChanged($relevantFields)) {
                return;
            }

            $this->handlePurge($retailer, $before);
        } catch (Throwable $e) {
            Log::error('RetailerObserver: saved event failure.', [
                'retailer_id' => $retailer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Retailer $retailer): void
    {
        $before = self::$stateBeforeUpdate[(int) $retailer->id] ?? null;
        unset(self::$stateBeforeUpdate[(int) $retailer->id]);

        try {
            self::forgetCachedSeoArtifacts();

            $urls = $this->collectUrls($retailer, $before, includeFlyers: false);

            if ($urls !== []) {
                PurgeCloudflareCacheJob::dispatch($urls)->afterCommit();

                Log::info('RetailerObserver: Dispatched purge on deleted.', [
                    'retailer_id' => $retailer->id,
                    'urls_count' => count($urls),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('RetailerObserver: deleted failed.', [
                'retailer_id' => $retailer->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{oldSlug: string, wasActive: bool}|null  $before
     */
    private function handlePurge(Retailer $retailer, ?array $before): void
    {
        try {
            self::forgetCachedSeoArtifacts();

            // If the retailer went inactive or its public identity changed,
            // every Published flyer page that cross-links it must be purged.
            $deactivated = $before !== null
                && $before['wasActive']
                && ! (bool) $retailer->is_active;
            $identityChanged = $retailer->wasChanged('name') || $retailer->wasChanged('logo_path');

            $urls = $this->collectUrls(
                $retailer,
                $before,
                includeFlyers: $deactivated || $identityChanged,
            );

            if ($urls === []) {
                return;
            }

            PurgeCloudflareCacheJob::dispatch($urls)->afterCommit();

            Log::info('RetailerObserver: Dispatched purge.', [
                'retailer_id' => $retailer->id,
                'urls_count' => count($urls),
            ]);
        } catch (Throwable $e) {
            Log::error('RetailerObserver: handlePurge failed.', [
                'retailer_id' => $retailer->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sitemap/llms/header caches are DB-derived — invalidate AFTER commit.
     * The auto-linker keyword maps are retailer-derived too: a renamed or
     * deactivated retailer must never keep injecting stale/404 links.
     */
    private static function forgetCachedSeoArtifacts(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');
            Cache::forget('layout:header_retailers');
            Cache::forget('auto_linker:retailers');
        });
    }

    /**
     * Canonical URLs from config('app.url') + relative routes — never url('/').
     *
     * @param  array{oldSlug: string, wasActive: bool}|null  $before
     * @return list<string>
     */
    private function collectUrls(Retailer $retailer, ?array $before, bool $includeFlyers = false): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $urls = [];

        $slugs = [];

        $currentSlug = (string) ($retailer->slug ?? '');
        if ($currentSlug !== '') {
            $slugs[] = $currentSlug;
        }

        $oldSlug = $before['oldSlug'] ?? '';
        if ($oldSlug !== '' && $oldSlug !== $currentSlug) {
            $slugs[] = $oldSlug;
        }

        foreach (array_unique($slugs) as $slug) {
            try {
                $urls[] = $base.route('retailers.show', ['retailer' => $slug], false);
            } catch (Throwable) {
                $urls[] = $base.'/'.rawurlencode($slug);
            }
        }

        // Public flyer pages of this retailer (hub cross-links + sitemap).
        if ($includeFlyers) {
            $flyerSlugs = Flyer::query()
                ->where('retailer_id', $retailer->id)
                ->where('status', FlyerStatus::Published)
                ->orderByDesc('valid_from')
                ->limit(100)
                ->pluck('slug');

            foreach ($flyerSlugs as $flyerSlug) {
                if ((string) $flyerSlug !== '') {
                    // DRY: resolve the path through the route definition — the
                    // /offers prefix must never drift from routes/web.php.
                    $urls[] = $base.route('flyers.show', ['slug' => (string) $flyerSlug], false);
                }
            }
        }

        $urls[] = $base.'/';
        $urls[] = $base.'/sitemap.xml';
        $urls[] = $base.'/llms.txt';

        return array_values(array_unique(array_filter(array_map(
            static fn (string $u): string => trim($u),
            $urls
        ))));
    }
}
