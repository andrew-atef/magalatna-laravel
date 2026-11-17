<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\Retailer;
use App\Services\ImageOptimizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BackfillFlyerThumbsCommand extends Command
{
    protected $signature = 'flyers:backfill-thumbs
                            {--discover : توليد صور Google Discover 1280x720 بدلاً من الصور المصغّرة 400px}';

    protected $description = 'توليد صور مصغّرة 400px (WebP) أو صور Google Discover 1280x720 لأغلفة المجلات السارية';

    public function handle(ImageOptimizerService $optimizer): int
    {
        if ($this->option('discover')) {
            return $this->backfillDiscover($optimizer);
        }

        // Active published flyers only: expired/archive cards are lazy-loaded
        // and never part of LCP, so backfilling them wastes R2 reads.
        $query = Flyer::query()
            ->where('status', FlyerStatus::Published)
            ->whereHas('pages', static fn ($q) => $q->where('page_number', 1)->whereNull('thumb_path'));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('جميع أغلفة المجلات السارية لديها صور مصغّرة بالفعل.');

            return self::SUCCESS;
        }

        $this->info("سيتم توليد صور مصغّرة لـ {$total} مجلة...");

        $processed = 0;
        $failed = 0;
        $affectedRetailerIds = [];

        $query->with([
            // Cover page row only (page_number = 1) — never hydrate full pages.
            'pages' => static fn ($q) => $q->where('page_number', 1)
                ->select(['id', 'flyer_id', 'page_number', 'image_path', 'thumb_path']),
        ])
            // chunkById keeps a stable cursor even while thumb_path updates.
            ->chunkById(25, function ($flyers) use ($optimizer, &$processed, &$failed, &$affectedRetailerIds): void {
                foreach ($flyers as $flyer) {
                    /** @var \App\Models\FlyerPage|null $coverPage */
                    $coverPage = $flyer->pages->first();

                    $masterPath = trim((string) ($coverPage?->image_path ?? ''));

                    if ($masterPath === '') {
                        $this->warn("مجلة بدون غلاف — تخطي [ID: {$flyer->id}]");
                        $failed++;

                        continue;
                    }

                    $masterBinary = null;
                    $thumbBinary = null;

                    try {
                        // Download the master ONCE from R2, generate the 400px
                        // thumb in-memory, store with immutable headers.
                        $masterBinary = Storage::disk('r2')->get($masterPath);

                        if (! is_string($masterBinary) || $masterBinary === '') {
                            throw new \RuntimeException('Master cover binary is empty on R2: '.$masterPath);
                        }

                        $thumbBinary = $optimizer->generateThumbWebp($masterBinary);
                        $thumbKey = $optimizer->storeThumbWebp($thumbBinary, 'flyers/'.$flyer->id);

                        // saveQuietly: no per-row CDN purge storm — the whole
                        // batch shares one deduplicated purge at the end.
                        $coverPage->forceFill(['thumb_path' => $thumbKey])->saveQuietly();

                        $processed++;
                        $affectedRetailerIds[(int) $flyer->retailer_id] = true;

                        $this->line("✔ {$flyer->title} — {$thumbKey}");
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("فشل توليد المصغّرة للمجلة [ID: {$flyer->id}]: ".$e->getMessage());

                        Log::error('BackfillFlyerThumbsCommand: thumbnail backfill failed for flyer.', [
                            'flyer_id' => $flyer->id,
                            'master_path' => $masterPath,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        // 128MB CLI budget: the master (~150KB WebP) and thumb
                        // buffer must NEVER survive to the next flyer iteration.
                        unset($masterBinary, $thumbBinary);
                        gc_collect_cycles();
                    }
                }
            });

        // One deduplicated CDN purge for everything the new thumb URLs touched:
        // the homepage, each affected retailer hub, sitemap and llms.txt.
        $base = rtrim((string) config('app.url'), '/');
        $allUrls = [$base.'/', $base.'/sitemap.xml', $base.'/llms.txt'];

        foreach (array_keys($affectedRetailerIds) as $retailerId) {
            $slug = Retailer::query()->whereKey($retailerId)->value('slug');

            if (is_string($slug) && $slug !== '') {
                $allUrls[] = $base.route('retailers.show', ['retailer' => $slug], false);
            }
        }

        $allUrls = array_values(array_unique(array_filter(array_map('strval', $allUrls))));

        foreach (array_chunk($allUrls, 30) as $chunk) {
            try {
                PurgeCloudflareCacheJob::dispatch($chunk);
            } catch (Throwable $e) {
                Log::error('BackfillFlyerThumbsCommand: failed to dispatch purge chunk.', [
                    'urls_count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('BackfillFlyerThumbsCommand: cover thumbnails backfilled.', [
            'processed' => $processed,
            'failed' => $failed,
            'purge_urls' => count($allUrls),
        ]);

        $this->info("تم الانتهاء: {$processed} مصغّرة ناجحة، {$failed} فاشلة.");

        return $failed > 0 && $processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Google Discover composite backfill (--discover): ONE 1280x720 16:9 hero
     * image per ACTIVE published flyer (valid_until >= Cairo today), used for
     * og:image / twitter:image / SaleEvent.image. Expired archive flyers are
     * never Discover candidates — noindex pages don't enter the feed.
     */
    private function backfillDiscover(ImageOptimizerService $optimizer): int
    {
        $today = \Carbon\Carbon::today('Africa/Cairo')->toDateString();

        $query = Flyer::query()
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $today)
            ->whereNull('discover_image_path');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('جميع المجلات السارية لديها صور Discover بالفعل.');

            return self::SUCCESS;
        }

        $this->info("سيتم توليد صور Discover 1280x720 لـ {$total} مجلة...");

        $processed = 0;
        $failed = 0;
        $affectedRetailerIds = [];
        $affectedFlyerSlugs = [];

        $query->with([
            // Cover (page 1) + page 2 rows only — the composite needs at most two.
            'pages' => static fn ($q) => $q->whereIn('page_number', [1, 2])
                ->select(['id', 'flyer_id', 'page_number', 'image_path', 'width', 'height']),
        ])
            // chunkById keeps a stable cursor even while discover_image_path updates.
            ->chunkById(25, function ($flyers) use ($optimizer, &$processed, &$failed, &$affectedRetailerIds, &$affectedFlyerSlugs): void {
                foreach ($flyers as $flyer) {
                    /** @var \App\Models\FlyerPage|null $coverPage */
                    $coverPage = $flyer->pages->firstWhere('page_number', 1);
                    /** @var \App\Models\FlyerPage|null $page2 */
                    $page2 = $flyer->pages->firstWhere('page_number', 2);

                    $masterPath = trim((string) ($coverPage?->image_path ?? ''));

                    if ($masterPath === '') {
                        $this->warn("مجلة بدون غلاف — تخطي [ID: {$flyer->id}]");
                        $failed++;

                        continue;
                    }

                    $coverBinary = null;
                    $page2Binary = null;
                    $discoverBinary = null;

                    try {
                        $coverBinary = Storage::disk('r2')->get($masterPath);

                        if (! is_string($coverBinary) || $coverBinary === '') {
                            throw new \RuntimeException('Master cover binary is empty on R2: '.$masterPath);
                        }

                        if ($page2 !== null && trim((string) $page2->image_path) !== '') {
                            try {
                                $page2Binary = Storage::disk('r2')->get((string) $page2->image_path);
                            } catch (Throwable $e) {
                                Log::warning('BackfillDiscover: page-2 fetch failed, cover-only composite.', [
                                    'flyer_id' => $flyer->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        $discoverBinary = $optimizer->generateDiscoverComposite(
                            (string) $coverBinary,
                            is_string($page2Binary) ? $page2Binary : null,
                        );
                        $discoverKey = $optimizer->storeDiscoverWebp($discoverBinary, 'flyers/'.$flyer->id);

                        // saveQuietly: no per-row CDN purge storm — the whole
                        // batch shares one deduplicated purge at the end.
                        $flyer->forceFill(['discover_image_path' => $discoverKey])->saveQuietly();

                        $processed++;
                        $affectedRetailerIds[(int) $flyer->retailer_id] = true;
                        $affectedFlyerSlugs[] = (string) $flyer->slug;

                        $this->line("✔ {$flyer->title} — {$discoverKey}");
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("فشل توليد صورة Discover للمجلة [ID: {$flyer->id}]: ".$e->getMessage());

                        Log::error('BackfillFlyerThumbsCommand: Discover backfill failed for flyer.', [
                            'flyer_id' => $flyer->id,
                            'master_path' => $masterPath,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        // 128MB CLI budget: master + page-2 + composite buffers
                        // must NEVER survive to the next flyer iteration.
                        unset($coverBinary, $page2Binary, $discoverBinary);
                        gc_collect_cycles();
                    }
                }
            });

        // One deduplicated CDN purge for everything the new og:image touched:
        // the flyer page itself (og:image/twitter:image/SaleEvent.image), the
        // retailer hub, the homepage, sitemap and llms.txt.
        $base = rtrim((string) config('app.url'), '/');
        $allUrls = [$base.'/', $base.'/sitemap.xml', $base.'/llms.txt'];

        foreach ($affectedFlyerSlugs as $slug) {
            if ($slug !== '') {
                $allUrls[] = $base.route('flyers.show', ['slug' => $slug], false);
            }
        }

        foreach (array_keys($affectedRetailerIds) as $retailerId) {
            $slug = Retailer::query()->whereKey($retailerId)->value('slug');

            if (is_string($slug) && $slug !== '') {
                $allUrls[] = $base.route('retailers.show', ['retailer' => $slug], false);
            }
        }

        $allUrls = array_values(array_unique(array_filter(array_map('strval', $allUrls))));

        foreach (array_chunk($allUrls, 30) as $chunk) {
            try {
                PurgeCloudflareCacheJob::dispatch($chunk);
            } catch (Throwable $e) {
                Log::error('BackfillFlyerThumbsCommand: failed to dispatch purge chunk.', [
                    'urls_count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('BackfillFlyerThumbsCommand: Discover composites backfilled.', [
            'processed' => $processed,
            'failed' => $failed,
            'purge_urls' => count($allUrls),
        ]);

        $this->info("تم الانتهاء: {$processed} صورة Discover ناجحة، {$failed} فاشلة.");

        return $failed > 0 && $processed === 0 ? self::FAILURE : self::SUCCESS;
    }
}
