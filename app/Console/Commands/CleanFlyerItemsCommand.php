<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Brand;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Services\ProductDataNormalizer;
use App\Support\ArabicNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 1 retroactive data cleanup: every flyer produced BEFORE the post-OCR
 * normalization pipeline shipped carries Gemini's raw output — misread units
 * (ا كجم), brand spelling drift (دولتين), no category, and cover-vs-inner
 * duplicate rows. This command re-applies the pipeline to ALL existing items
 * (normalize → categorize → Richest-Row dedup), rebuilds the SEO copy with
 * the DETERMINISTIC fallback builders, and purges the CDN for the affected
 * live pages.
 */
final class CleanFlyerItemsCommand extends Command
{
    protected $signature = 'flyers:clean-items
        {--dry-run : عرض التغييرات المقترحة دون حفظ أي بيانات}';

    protected $description = 'تنظيف وتوحيد بيانات سلع المجلات: تصحيح أسماء المنتجات والبراندات، التصنيف التلقائي، وإزالة التكرار (أغنى صف يفوز) مع إعادة بناء النصوص الترويجية';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $flyerIds = Flyer::query()
            ->whereHas('items')
            ->orderBy('id')
            ->pluck('id');

        $total = $flyerIds->count();

        if ($total === 0) {
            $this->info('لا توجد مجلات تحتوي على سلع للتنظيف.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "بدء الفحص التجريبي لـ {$total} مجلة (لن تُحفظ أي تغييرات)..."
            : "بدء تنظيف {$total} مجلة...");

        $stats = [
            'flyers' => 0,
            'items_renamed' => 0,
            'brands_resolved' => 0,
            'items_categorized' => 0,
            'duplicates_removed' => 0,
            'flyers_changed' => 0,
            'copy_rebuilt' => 0,
        ];

        /** @var list<int> $changedFlyerIds flyers whose PUBLIC page must be purged */
        $changedFlyerIds = [];

        foreach ($flyerIds->chunk(25) as $chunk) {
            /** @var \Illuminate\Support\Collection<int, Flyer> $flyers */
            $flyers = Flyer::query()
                ->with(['items', 'retailer'])
                ->whereIn('id', $chunk->all())
                ->orderBy('id')
                ->get();

            foreach ($flyers as $flyer) {
                $flyerChanged = false;

                try {
                    // One transaction per flyer: a normalize failure rolls back
                    // THIS flyer only and never poisons the rest of the chunk.
                    DB::transaction(function () use ($flyer, $dryRun, &$stats, &$changedFlyerIds, &$flyerChanged): void {
                        $itemsChanged = false;

                        foreach ($flyer->items as $item) {
                            /** @var FlyerItem $item */
                            $dirty = false;
                            $originalName = trim((string) $item->product_name);

                            // --- 1. Product-name normalization -----------------
                            $cleaned = $originalName === '' ? '' : ProductDataNormalizer::cleanProductName($originalName);

                            if ($cleaned !== '' && $cleaned !== $originalName) {
                                // saveQuietly() skips FlyerItemObserver, which is
                                // what normally fills normalized_name — update it
                                // MANUALLY alongside the rename.
                                $item->product_name = $cleaned;
                                $item->normalized_name = ArabicNormalizer::normalize($cleaned) ?: $cleaned;
                                $dirty = true;
                                $stats['items_renamed']++;
                            }

                            $productName = $cleaned !== '' ? $cleaned : $originalName;

                            // --- 2. Brand canonicalization ---------------------
                            $currentBrandName = $item->brand?->name;
                            $canonicalBrand = ProductDataNormalizer::canonicalBrandName(
                                $currentBrandName !== null ? trim((string) $currentBrandName) : null,
                                $productName,
                            );

                            if (is_string($canonicalBrand) && trim($canonicalBrand) !== ''
                                && ($currentBrandName === null || trim((string) $currentBrandName) !== trim($canonicalBrand))) {
                                $brandId = $this->resolveBrandId(trim($canonicalBrand));

                                if ($brandId !== null && $brandId !== $item->brand_id) {
                                    $item->brand_id = $brandId;
                                    $dirty = true;
                                    $stats['brands_resolved']++;
                                }
                            }

                            // --- 3. Deterministic categorization ---------------
                            $category = ProductDataNormalizer::inferCategory($productName);

                            if ((string) $item->category !== $category) {
                                $item->category = $category;
                                $dirty = true;
                                $stats['items_categorized']++;
                            }

                            if ($dirty && ! $dryRun) {
                                $item->saveQuietly();
                                $itemsChanged = true;
                            } elseif ($dirty) {
                                $itemsChanged = true;
                            }
                        }

                        // --- 4. Richest-Row Winner dedup -----------------------
                        if (! $dryRun) {
                            // (survivor saves + deletions happen inside)
                            $removed = GatekeeperFacebookPostJob::dedupFlyerItems($flyer);
                            $stats['duplicates_removed'] += $removed;

                            if ($removed > 0) {
                                $itemsChanged = true;
                            }
                        } else {
                            // Dry-run: count would-be duplicates WITHOUT touching the DB.
                            $deduplicatable = $flyer->items
                                ->filter(static fn (FlyerItem $item): bool => trim((string) $item->product_name) !== '');

                            if ($deduplicatable->count() >= 2) {
                                /** @var array{kept: list<FlyerItem>, removedIds: list<int>} $result */
                                $result = ProductDataNormalizer::deduplicate($deduplicatable->values()->all());
                                $stats['duplicates_removed'] += count($result['removedIds']);

                                if ($result['removedIds'] !== []) {
                                    $itemsChanged = true;
                                }
                            }
                        }

                        if ($itemsChanged) {
                            $flyerChanged = true;
                            $stats['flyers_changed']++;
                            $changedFlyerIds[] = (int) $flyer->id;
                        }

                        $stats['flyers']++;
                    });

                    // --- 5. Rebuild BLUF + editorial (deterministic fallback) ---
                    if ($flyerChanged && ! $dryRun) {
                        // Blank the Gemini key: generateBlufSummary()/Editorial()
                        // throw immediately in apiKey(), the Gatekeeper builders
                        // catch it, and the DETERMINISTIC fallback copy is built —
                        // no AI cost, no rate limits, fully reproducible output.
                        config()->set('services.gemini.key', '');

                        $flyer->bluf_summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);
                        $flyer->editorial_overview = GatekeeperFacebookPostJob::buildEditorialOverview($flyer);
                        $flyer->saveQuietly();
                        $stats['copy_rebuilt']++;
                    }
                } catch (Throwable $e) {
                    Log::error('CleanFlyerItemsCommand: flyer cleanup failed.', [
                        'flyer_id' => $flyer->id,
                        'dry_run' => $dryRun,
                        'error' => $e->getMessage(),
                    ]);

                    $this->warn("فشل تنظيف المجلة #{$flyer->id}: {$e->getMessage()}");
                }
            }

            // Memory hygiene: release the hydrated chunk before the next one.
            unset($flyers);
            gc_collect_cycles();
        }

        // --- 6. Cache invalidation + CDN purge (only when really writing) -------
        if (! $dryRun) {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            $base = rtrim((string) config('app.url'), '/');
            $allUrls = [$base.'/', $base.'/sitemap.xml', $base.'/llms.txt'];

            // Only ACTIVE published flyers changed public content — expired or
            // pending pages are not indexable/purge-worthy.
            $published = Flyer::query()
                ->with('retailer')
                ->whereIn('id', $changedFlyerIds)
                ->where('status', \App\Enums\FlyerStatus::Published->value)
                ->get();

            foreach ($published as $flyer) {
                $allUrls[] = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                if ($flyer->retailer !== null && (string) $flyer->retailer->slug !== '') {
                    $allUrls[] = $base.route('retailers.show', ['retailer' => (string) $flyer->retailer->slug], false);
                }
            }

            $allUrls = array_values(array_unique(array_filter(array_map(
                static fn (mixed $u): string => trim((string) $u),
                $allUrls,
            ))));

            // Respect Cloudflare Edge API batch limits: max 30 URLs per purge.
            foreach (array_chunk($allUrls, 30) as $index => $chunk) {
                try {
                    PurgeCloudflareCacheJob::dispatch($chunk);
                } catch (Throwable $e) {
                    Log::error('CleanFlyerItemsCommand: failed to dispatch purge chunk.', [
                        'chunk' => $index,
                        'urls_count' => count($chunk),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $mode = $dryRun ? '(تجريبي — لم يُحفظ شيء)' : '';

        $this->info("تمت المعالجة {$stats['flyers']}/{$total} مجلة {$mode}");
        $this->table(['العملية', 'العدد'], [
            ['أسماء منتجات تم تصحيحها', (string) $stats['items_renamed']],
            ['براندات تم توحيدها', (string) $stats['brands_resolved']],
            ['تصنيفات تم تعيينها', (string) $stats['items_categorized']],
            ['صفوف مكررة تم حذفها', (string) $stats['duplicates_removed']],
            ['مجلات متأثرة', (string) $stats['flyers_changed']],
            ['نصوص ترويجية أعيد بناؤها', (string) $stats['copy_rebuilt']],
        ]);

        Log::info('CleanFlyerItemsCommand finished.', $stats + ['dry_run' => $dryRun]);

        return self::SUCCESS;
    }

    /**
     * Resolve a canonical brand name to a `brands` row — the SAME
     * firstOrCreate + unique-slug pattern ProcessSinglePageJob uses.
     */
    private function resolveBrandId(string $canonicalName): ?int
    {
        $brandSlug = Str::slug($canonicalName);

        if ($brandSlug === '') {
            $brandSlug = 'brand-'.Str::lower(Str::ulid()->toString());
        }

        try {
            $brand = Brand::firstOrCreate(
                ['name' => $canonicalName],
                ['slug' => $this->uniqueBrandSlug($brandSlug)],
            );

            return (int) $brand->id;
        } catch (Throwable $e) {
            // Unique-constraint race with a concurrent worker: re-query.
            try {
                $brand = Brand::query()->where('name', $canonicalName)->first();

                return $brand?->id;
            } catch (Throwable $re) {
                Log::warning('CleanFlyerItemsCommand: brand resolution failed.', [
                    'brand' => $canonicalName,
                    'error' => $re->getMessage(),
                ]);

                return null;
            }
        }
    }

    private function uniqueBrandSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 0;

        while (Brand::where('slug', $slug)->exists()) {
            $counter++;
            $slug = $baseSlug.'-'.$counter;

            if ($counter > 10) {
                $slug = $baseSlug.'-'.Str::lower(Str::ulid()->toString());

                break;
            }
        }

        return $slug;
    }
}
