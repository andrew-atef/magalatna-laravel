<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GeminiRateLimitedException;
use App\Models\Brand;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Services\GeminiVisionService;
use App\Services\ProductDataNormalizer;
use App\Support\ArabicNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Safe AI-content regeneration for an EXISTING flyer (Filament admin action
 * or `php artisan flyers:regenerate`).
 *
 *  - 'copy' (fast): rebuild BLUF + editorial copy from the CURRENT items.
 *    Two AI text calls, zero OCR.
 *  - 'full': re-OCR every page from its STORED R2 binary (never the expired
 *    RawFacebookPost.image_urls Facebook CDN links), stage the normalized
 *    items, and swap them in ONLY when the new extraction yielded priced
 *    items — a failed/empty OCR run never wipes a live flyer's items.
 *
 * The SEO slug is NEVER touched (Flyer::updating guards it anyway). The final
 * audited save() fires FlyerObserver: Cloudflare edge purge + sitemap.xml /
 * llms.txt cache refresh + IndexNow ping on publish transitions.
 *
 * 840MB RAM safety: one page binary in memory at a time, freed with
 * unset() + gc_collect_cycles() at the end of every page iteration; ZERO
 * network calls inside the SQLite swap transaction.
 */
final class RegenerateFlyerContentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 50;

    /**
     * Temporary waterfall release() loops never count toward permanent
     * failure — only genuinely thrown exceptions do.
     */
    public int $maxExceptions = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $flyerId,
        public readonly string $mode = 'full',
    ) {}

    public function handle(GeminiVisionService $gemini): void
    {
        $flyer = Flyer::query()
            ->with(['retailer', 'items', 'pages' => fn ($q) => $q->orderBy('page_number')])
            ->find($this->flyerId);

        if ($flyer === null) {
            Log::warning('[REGENERATE] Flyer not found, nothing to do.', ['flyer_id' => $this->flyerId]);

            return;
        }

        $mode = $this->mode === 'copy' ? 'copy' : 'full';

        try {
            if ($mode === 'full') {
                $this->regenerateItemsFromR2($flyer, $gemini);
            }

            // Recount from the DB (never trust a stale counter), rebuild the
            // AI copy + Discover hero, then ONE audited save() so the observer
            // purges the CDN edge and refreshes sitemap/llms exactly once.
            $flyer->total_pages = (int) $flyer->pages()->count();
            $flyer->bluf_summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);
            $flyer->editorial_overview = GatekeeperFacebookPostJob::buildEditorialOverview($flyer);
            GatekeeperFacebookPostJob::ensureDiscoverImage($flyer);
            $flyer->save();

            Log::info('[REGENERATE] Content regeneration completed.', [
                'flyer_id' => $flyer->id,
                'slug' => $flyer->slug,
                'mode' => $mode,
                'items_count' => $flyer->items()->where('sale_price', '>', 0)->count(),
            ]);
        } catch (GeminiRateLimitedException $e) {
            // The waterfall's own 503 cooldown is 60s (TRANSIENT_COOLDOWN_SECONDS);
            // releasing sooner would start the next attempt with every model
            // still blocked, wasting a whole attempt. Always outwait it.
            $wait = max(65, $e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : 20);

            $this->release($wait);
        } catch (Throwable $e) {
            Log::error('[REGENERATE] Content regeneration failed.', [
                'flyer_id' => $flyer->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * FULL mode: re-OCR from stored R2 page binaries with zero-data-loss
     * staging. Old items are deleted ONLY inside the swap transaction after
     * the new extraction proved it has priced items.
     *
     * Resumable staging: a page throttled by the Gemini waterfall must
     * never discard the pages already OCR'd by earlier attempts. The
     * checkpoint is keyed by the flyer AND its exact page set, so a
     * re-ingested flyer (different pages) invalidates it automatically.
     */
    private function regenerateItemsFromR2(Flyer $flyer, GeminiVisionService $gemini): void
    {
        // Resumable staging checkpoint
        $pageIds = $flyer->pages->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $checkpointKey = 'regen_stage:'.$flyer->id;
        $checkpoint = Cache::get($checkpointKey);

        if (
            ! is_array($checkpoint)
            || ($checkpoint['page_ids'] ?? null) !== $pageIds
            || ! is_array($checkpoint['staged'] ?? null)
        ) {
            $checkpoint = ['page_ids' => $pageIds, 'staged' => [], 'total' => 0];
        }

        /** @var array<int, list<array<string, mixed>>> $staged */
        $staged = $checkpoint['staged'];
        $stagedTotal = (int) $checkpoint['total'];

        foreach ($flyer->pages as $page) {
            // Already extracted by a previous (released) attempt.
            if (array_key_exists((int) $page->id, $staged)) {
                continue;
            }

            $path = trim((string) $page->image_path);

            if ($path === '') {
                Log::warning('[REGENERATE] Page without image_path, skipping.', [
                    'flyer_id' => $flyer->id,
                    'page_id' => $page->id,
                ]);

                continue;
            }

            // NEVER re-fetch expired Facebook CDN URLs — the durable R2
            // object is the single source of truth for re-OCR.
            try {
                $binary = Storage::disk('r2')->get($path);
            } catch (Throwable $e) {
                Log::warning('[REGENERATE] R2 page read failed, skipping page.', [
                    'flyer_id' => $flyer->id,
                    'page_id' => $page->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_string($binary) || $binary === '') {
                Log::warning('[REGENERATE] R2 page binary missing/empty, skipping page.', [
                    'flyer_id' => $flyer->id,
                    'page_id' => $page->id,
                    'path' => $path,
                ]);

                continue;
            }

            try {
                try {
                    // Ordered multi-model waterfall.
                    $items = $gemini->extractProductsFromPage($binary);
                } catch (GeminiRateLimitedException $rateLimited) {
                    // One short in-run retry rides out a brief model flap.
                    // Progress is persisted first, so a second throttle
                    // releases the job and the next attempt resumes HERE.
                    Cache::put($checkpointKey, [
                        'page_ids' => $pageIds,
                        'staged' => $staged,
                        'total' => $stagedTotal,
                    ], now()->addHours(3));

                    sleep(min(max(5, $rateLimited->retryAfterSeconds), 12));

                    $items = $gemini->extractProductsFromPage($binary);
                }
            } finally {
                // 840MB RAM safety: free the page buffer before the next one.
                unset($binary);
                gc_collect_cycles();
            }

            $normalized = [];

            foreach ($items as $raw) {
                $row = $this->normalizeStagedItem($raw);

                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            $staged[(int) $page->id] = $normalized;
            $stagedTotal += count($normalized);

            // Durable progress: surviving a release is what makes a long
            // re-OCR converge while the free-tier models flap.
            Cache::put($checkpointKey, [
                'page_ids' => $pageIds,
                'staged' => $staged,
                'total' => $stagedTotal,
            ], now()->addHours(3));

            unset($items, $normalized);
            gc_collect_cycles();
        }

        // Coverage guard: a partial staging pass must NEVER swap. Every page
        // needs a result (an empty array is a valid result), so a released /
        // resumed run can only commit once the WHOLE flyer has been read.
        if (count($staged) !== count($pageIds)) {
            Log::warning('[REGENERATE_INCOMPLETE] Partial page coverage — existing items preserved.', [
                'flyer_id' => $flyer->id,
                'staged_pages' => count($staged),
                'total_pages' => count($pageIds),
            ]);

            throw new RuntimeException(
                'Full regeneration staged '.count($staged).'/'.count($pageIds)
                .' pages for flyer #'.$flyer->id.' — existing items were preserved.'
            );
        }

        // Volume guard: a throttled/degraded OCR pass must never replace a
        // healthy item set with a materially smaller one.
        $existingPriced = $flyer->items()->where('sale_price', '>', 0)->count();

        if ($existingPriced > 0 && $stagedTotal < (int) floor($existingPriced * 0.8)) {
            Log::warning('[REGENERATE_SHRINK_GUARD] New extraction materially smaller — existing items preserved.', [
                'flyer_id' => $flyer->id,
                'existing_priced' => $existingPriced,
                'new_priced' => $stagedTotal,
            ]);

            throw new RuntimeException(
                'Full regeneration would shrink priced items '.$existingPriced.' → '.$stagedTotal
                .' for flyer #'.$flyer->id.' — existing items were preserved.'
            );
        }

        if ($stagedTotal === 0) {
            // ZERO-DATA-LOSS: an empty/failed extraction must NEVER wipe a
            // live flyer. Existing items stay intact; fail visibly instead.
            Log::warning('[REGENERATE_ZERO_ITEMS] Full regeneration extracted 0 priced items — existing items preserved.', [
                'flyer_id' => $flyer->id,
                'pages' => $flyer->pages->count(),
            ]);

            throw new RuntimeException(
                'Full regeneration extracted 0 priced items for flyer #'.$flyer->id.' — existing items were preserved.'
            );
        }

        // Swap transaction: DB-only, ZERO network calls inside.
        DB::transaction(function () use ($flyer, $staged): void {
            FlyerItem::withoutEvents(function () use ($flyer, $staged): void {
                FlyerItem::query()->where('flyer_id', $flyer->id)->delete();

                foreach ($staged as $pageId => $rows) {
                    foreach ($rows as $row) {
                        FlyerItem::create([
                            'flyer_id' => $flyer->id,
                            'flyer_page_id' => $pageId,
                            'brand_id' => $this->resolveBrandId($row['brand_name'] ?? null),
                            'product_name' => $row['product_name'],
                            'slug' => $this->itemSlug((string) $row['product_name']),
                            'normalized_name' => $row['normalized_name'],
                            'sale_price' => $row['sale_price'],
                            'old_price' => $row['old_price'],
                            'savings_amount' => $row['savings_amount'],
                            'discount_percent' => $row['discount_percent'],
                            'unit' => $row['unit'],
                            'category' => $row['category'],
                            'bundle_condition' => $row['bundle_condition'],
                            'extra_attributes' => $row['extra_attributes'],
                            'is_featured' => false,
                        ]);
                    }
                }
            });

            // Richest-Row Winner dedup (public static, DB-only — safe in tx).
            GatekeeperFacebookPostJob::dedupFlyerItems($flyer);
        });

        // The eager-loaded items collection is stale after the swap.
        $flyer->unsetRelation('items');

        // Staging is complete and committed — drop the checkpoint so a later
        // regeneration starts from a clean slate.
        Cache::forget($checkpointKey);
    }

    /**
     * Deterministic post-OCR normalization — same rules as
     * ProcessSinglePageJob (clean name, price guards, canonical brand,
     * closed-vocabulary category). Returns null for unusable rows.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalizeStagedItem(array $raw): ?array
    {
        $productName = trim((string) ($raw['product_name'] ?? ''));

        if ($productName === '') {
            return null;
        }

        $salePrice = (float) ($raw['sale_price'] ?? 0);

        if ($salePrice <= 0) {
            return null;
        }

        $productName = ProductDataNormalizer::cleanProductName($productName) ?: $productName;

        $oldPrice = isset($raw['old_price']) && $raw['old_price'] !== null ? (float) $raw['old_price'] : null;
        $savingsAmount = null;
        $discountPercent = null;

        if ($oldPrice !== null && $oldPrice > 0 && $oldPrice > $salePrice) {
            $savingsAmount = round($oldPrice - $salePrice, 2);
            $discountPercent = round(($savingsAmount / $oldPrice) * 100, 2);

            if ($discountPercent > 90) {
                Log::warning('[REGENERATE] Unrealistic discount percent, possible superscript misread.', [
                    'product_name' => $productName,
                    'sale_price' => $salePrice,
                    'old_price' => $oldPrice,
                ]);
            }
        } elseif ($oldPrice !== null && $oldPrice <= $salePrice) {
            $oldPrice = null;
        }

        $rawBrand = $raw['brand_name'] ?? null;
        $canonicalBrand = ProductDataNormalizer::canonicalBrandName(
            is_string($rawBrand) && trim($rawBrand) !== '' ? trim($rawBrand) : null,
            $productName,
        );

        $rawCategory = $raw['category'] ?? null;
        $category = ProductDataNormalizer::inferCategory(
            $productName,
            is_string($rawCategory) ? trim($rawCategory) : null,
        );

        $unit = $raw['unit'] ?? null;
        $bundle = $raw['bundle_condition'] ?? null;
        $extra = $raw['extra_attributes'] ?? null;

        return [
            'product_name' => $productName,
            'sale_price' => $salePrice,
            'old_price' => $oldPrice,
            'savings_amount' => $savingsAmount,
            'discount_percent' => $discountPercent,
            'unit' => is_string($unit) && trim($unit) !== '' ? trim($unit) : null,
            'category' => $category,
            'brand_name' => is_string($canonicalBrand) && trim($canonicalBrand) !== '' ? trim($canonicalBrand) : null,
            'bundle_condition' => is_string($bundle) && trim($bundle) !== '' ? trim($bundle) : null,
            'extra_attributes' => is_array($extra) && $extra !== [] ? $extra : null,
            'normalized_name' => ArabicNormalizer::normalize($productName) ?: $productName,
        ];
    }

    /**
     * Brand resolution inside the swap transaction (DB-only). A unique-slug
     * race with a concurrent worker falls back to a re-query, never drops
     * the brand.
     */
    private function resolveBrandId(mixed $brandName): ?int
    {
        $name = is_string($brandName) ? trim($brandName) : '';

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'brand-'.Str::lower(Str::ulid()->toString());
        }

        try {
            $brand = Brand::firstOrCreate(['name' => $name], ['slug' => $this->uniqueBrandSlug($slug)]);

            return (int) $brand->id;
        } catch (Throwable) {
            $id = Brand::query()->where('name', $name)->value('id');

            return $id !== null ? (int) $id : null;
        }
    }

    private function uniqueBrandSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 0;

        while (Brand::query()->where('slug', $slug)->exists()) {
            $counter++;
            $slug = $baseSlug.'-'.$counter;

            if ($counter > 10) {
                $slug = $baseSlug.'-'.Str::lower(Str::ulid()->toString());
                break;
            }
        }

        return $slug;
    }

    private function itemSlug(string $productName): string
    {
        $slug = Str::slug($productName);

        if ($slug === '') {
            $slug = 'product-'.Str::lower(Str::ulid()->toString());
        }

        return $slug.'-'.substr(Str::ulid()->toString(), -4);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[REGENERATE] Job permanently failed.', [
            'flyer_id' => $this->flyerId,
            'mode' => $this->mode,
            'error' => $exception->getMessage(),
        ]);
    }
}