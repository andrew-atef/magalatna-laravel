<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GeminiRateLimitedException;
use App\Models\Brand;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Services\GeminiVisionService;
use App\Services\ImageOptimizerService;
use App\Services\ProductDataNormalizer;
use App\Support\ArabicNormalizer;
use App\Support\PublicUrlGuard;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ProcessSinglePageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 15;

    /**
     * Temporary GeminiRateLimitedException release() loops must never count
     * toward permanent failure — only genuinely thrown exceptions do, so an
     * RPM burst across the model waterfall can never trip MaxAttemptsExceeded.
     */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 180;

    /** Hard cap per image download — protects the 128MB queue worker. */
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        public readonly int $flyerId,
        public readonly string $imageUrl,
        public readonly int $pageNumber,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        GeminiVisionService $gemini,
        ImageOptimizerService $optimizer,
    ): void {
        // Check batch cancellation
        if ($this->batch() !== null && $this->batch()->cancelled()) {
            Log::info('ProcessSinglePageJob cancelled via batch.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
            ]);

            return;
        }

        try {
            // Per-model RPM/RPD quotas + the ordered model waterfall live
            // INSIDE GeminiVisionService now — no global 'gemini-api-limit'
            // gate here. When every model is temporarily throttled the
            // waterfall throws GeminiRateLimitedException and this job
            // releases itself, freeing the timeout-bounded worker.
            $this->processPage($gemini, $optimizer);
        } catch (GeminiRateLimitedException $e) {
            // Gemini answered 429 — free the worker immediately and retry the
            // job after the rate-limit window instead of sleeping in-process.
            Log::warning('ProcessSinglePageJob hit Gemini rate limit, releasing job.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'retry_after_seconds' => $e->retryAfterSeconds,
            ]);

            $this->release($e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : 30);

            return;
        } catch (Throwable $e) {
            Log::error('ProcessSinglePageJob failed outside throttle.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'image_url' => $this->imageUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Core page processing logic.
     *
     * @throws Throwable
     */
    private function processPage(
        GeminiVisionService $gemini,
        ImageOptimizerService $optimizer,
    ): void {
        try {
            Log::info('ProcessSinglePageJob started.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'image_url' => $this->imageUrl,
            ]);

            $flyer = Flyer::find($this->flyerId);

            if ($flyer === null) {
                Log::error('Flyer not found for ProcessSinglePageJob.', [
                    'flyer_id' => $this->flyerId,
                ]);

                return;
            }

            // 1. SSRF guard BEFORE any byte is fetched: only public http(s)
            // origins. Facebook CDN URLs arrive from an external webhook —
            // a crafted URL must never probe 127.0.0.1/169.254.169.254 or the
            // VPS's private network.
            PublicUrlGuard::assertPublicHttpUrl($this->imageUrl);

            $rawPath = (string) parse_url($this->imageUrl, PHP_URL_PATH);
            $isPdf = str_ends_with(strtolower($rawPath), '.pdf');

            // 2. Download raw image binary ONCE (defensive timeout).
            // Every downstream step (R2 upload + Gemini OCR) reuses these bytes —
            // never download the same asset twice within one job cycle.
            // PDFs are the one exception: the optimizer re-downloads internally
            // for multi-page rendering, so skip the wasted fetch here.
            $rawBinary = $isPdf ? null : $this->downloadRawImage($this->imageUrl);

            // 3. Optimize in-memory and store the WebP on R2.
            $directory = 'flyers/'.$this->flyerId;
            $webpBinary = null;

            try {
                if ($isPdf) {
                    $r2Key = $optimizer->processAndUpload($this->imageUrl, $directory);
                } else {
                    /** @var string $rawBinary */
                    $webpBinary = $optimizer->convertToWebp($rawBinary);
                    $r2Key = $optimizer->storeWebp($webpBinary, $directory, 'r2');
                }
            } catch (Throwable $e) {
                unset($rawBinary, $webpBinary);
                gc_collect_cycles();

                Log::error('ImageOptimizerService failed, will attempt direct R2 upload fallback.', [
                    'flyer_id' => $this->flyerId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            // Real pixel dimensions from the converted WebP (never hardcoded),
            // read BEFORE the buffer is freed below. PDF path: $rawBinary is
            // null here (the optimizer re-downloads PDFs internally for
            // multi-page rendering) and $webpBinary is also null — so load
            // the converted single WebP back from R2 BEFORE pageDimensions()
            // and extractProductsFromPage(); passing null to either is a
            // fatal TypeError.
            if ($isPdf) {
                $pdfWebp = Storage::disk('r2')->get($r2Key);

                if (! is_string($pdfWebp) || $pdfWebp === '') {
                    throw new \RuntimeException('Converted PDF page missing on R2 after upload: '.$r2Key);
                }

                // One R2 read feeds BOTH the dimension scan and the OCR call;
                // copy-on-write keeps this a single shared buffer.
                $rawBinary = $pdfWebp;
                $webpBinary = $pdfWebp;
                unset($pdfWebp);
            }

            [$width, $height] = $this->pageDimensions($webpBinary);

            // 3b. Cover-page thumbnail (page 1 ONLY): a lightweight 400px WebP
            // (~15-22KB) feeds every grid card — homepage, retailer hub,
            // cross-link widgets — so mobile LCP transfers kilobytes instead
            // of the ~150KB 1200x1600 master. Both source branches feed this:
            // image pages carry $webpBinary directly, the PDF branch re-reads
            // the converted page from R2 into $webpBinary above.
            // PROGRESSIVE ENHANCEMENT: a thumbnail failure must never fail the
            // master-page pipeline — cards fall back to image_path.
            $thumbKey = null;
            $replacedOldThumbPath = null;

            if ($this->pageNumber === 1 && is_string($webpBinary) && $webpBinary !== '') {
                $thumbBinary = null;

                try {
                    $thumbBinary = $optimizer->generateThumbWebp($webpBinary);
                    $thumbKey = $optimizer->storeThumbWebp($thumbBinary, 'flyers/'.$this->flyerId);
                } catch (Throwable $e) {
                    Log::warning('Cover thumbnail generation failed (non-fatal, cards fall back to master).', [
                        'flyer_id' => $this->flyerId,
                        'page_number' => $this->pageNumber,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    unset($thumbBinary);
                    gc_collect_cycles();
                }
            }

            // WebP bytes are on R2 — free the buffer before the OCR call so the
            // 128MB worker never holds raw + webp + response simultaneously.
            unset($webpBinary);
            gc_collect_cycles();

            Log::info('Image uploaded to R2.', [
                'flyer_id' => $this->flyerId,
                'r2_key' => $r2Key,
            ]);

            // 4. Call Gemini Vision OCR (high-density)
            /** @var string $rawBinary */
            $items = $gemini->extractProductsFromPage($rawBinary);

            unset($rawBinary);
            gc_collect_cycles();

            if ($items === []) {
                Log::warning('Gemini OCR returned no items for page.', [
                    'flyer_id' => $this->flyerId,
                    'page_number' => $this->pageNumber,
                ]);
            }

            Log::info('Gemini OCR extracted items.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'count' => count($items),
            ]);

            // 5. Insert FlyerPage and FlyerItems within transaction — WITHOUT cascade cache purge
            $replacedOldPath = null;

            DB::transaction(function () use ($flyer, $r2Key, $items, $width, $height, $thumbKey, &$replacedOldPath, &$replacedOldThumbPath): void {
                // Capture the pre-existing image path BEFORE the upsert: on a
                // retry the page row may already point at a different R2 object
                // which must be reaped after this commit (never leaked). The
                // old thumb (cover retries) is captured the same way.
                $previous = FlyerPage::query()
                    ->where('flyer_id', $flyer->id)
                    ->where('page_number', $this->pageNumber)
                    ->first(['id', 'image_path', 'thumb_path']);

                if ($previous !== null) {
                    $oldPath = trim((string) $previous->image_path);
                    if ($oldPath !== '' && $oldPath !== $r2Key) {
                        $replacedOldPath = $oldPath;
                    }

                    $oldThumbPath = trim((string) $previous->thumb_path);
                    if ($oldThumbPath !== '' && $oldThumbPath !== $thumbKey) {
                        $replacedOldThumbPath = $oldThumbPath;
                    }
                }

                $pageAttributes = [
                    'image_path' => $r2Key,
                    'width' => $width,
                    'height' => $height,
                ];

                if ($this->pageNumber === 1) {
                    // Cover only: persist the generated thumbnail key. When
                    // generation failed ($thumbKey === null) the stale thumb is
                    // cleared — the master changed, the old thumb no longer
                    // matches, and it is reaped after commit below.
                    $pageAttributes['thumb_path'] = $thumbKey;
                }

                // Upsert FlyerPage (unique flyer_id + page_number) — no observer, safe
                $flyerPage = FlyerPage::updateOrCreate(
                    [
                        'flyer_id' => $flyer->id,
                        'page_number' => $this->pageNumber,
                    ],
                    $pageAttributes
                );

                Log::info('FlyerPage upserted.', [
                    'flyer_page_id' => $flyerPage->id,
                    'flyer_id' => $flyer->id,
                ]);

                // Prevent cascade purge storm: disable FlyerItemObserver touch on every insert
                FlyerItem::withoutEvents(function () use ($flyer, $flyerPage, $items): void {
                    // Clear previous items for this specific page to ensure idempotency on retries
                    FlyerItem::where('flyer_page_id', $flyerPage->id)->delete();

                    // Phase 1 smart dedup: load THIS flyer's existing items ONCE
                    // (ordered by id — earlier reads win survivorship) so cover-vs-
                    // inner-page double reads and intra-page dupes match in memory
                    // via areSameProduct() instead of one exact-match query per row.
                    $flyerItems = FlyerItem::query()
                        ->where('flyer_id', $flyer->id)
                        ->orderBy('id')
                        ->get()
                        ->all();

                    foreach ($items as $raw) {
                        try {
                            $productName = trim((string) ($raw['product_name'] ?? ''));

                            if ($productName === '') {
                                Log::warning('Skipping item without product_name.', [
                                    'flyer_id' => $flyer->id,
                                    'page_number' => $this->pageNumber,
                                ]);

                                continue;
                            }

                            $salePrice = (float) ($raw['sale_price'] ?? 0);
                            if ($salePrice <= 0) {
                                Log::warning('Skipping item with zero or negative price in ProcessSinglePageJob.', [
                                    'product_name' => $productName,
                                    'sale_price' => $salePrice,
                                ]);

                                continue;
                            }

                            // Phase 1 post-OCR normalization: digits, OCR
                            // letter-for-digit unit errors (ا كجم → 1 كجم),
                            // canonical typo corrections (دولتين → دولفين).
                            $productName = ProductDataNormalizer::cleanProductName($productName) ?: $productName;

                            $oldPrice = isset($raw['old_price']) && $raw['old_price'] !== null ? (float) $raw['old_price'] : null;
                            $unit = $raw['unit'] ?? null;
                            $brandName = $raw['brand_name'] ?? null;
                            $bundleCondition = $raw['bundle_condition'] ?? null;
                            $extraAttributes = $raw['extra_attributes'] ?? null;

                            // Phase 1 deterministic categorization — the keyword
                            // classifier overrides the AI's own answer.
                            $category = ProductDataNormalizer::inferCategory(
                                $productName,
                                is_string($raw['category'] ?? null) ? trim((string) $raw['category']) : null,
                            );

                            // Calculate savings and discount percent if old_price present and valid
                            $savingsAmount = null;
                            $discountPercent = null;

                            if ($oldPrice !== null && $oldPrice > 0 && $oldPrice > $salePrice) {
                                $savingsAmount = round($oldPrice - $salePrice, 2);
                                $discountPercent = round(($savingsAmount / $oldPrice) * 100, 2);

                                // Guard against unrealistic discounts >90% (likely superscript misread)
                                if ($discountPercent > 90) {
                                    Log::warning('Unrealistic discount percent, possible superscript parse error.', [
                                        'product_name' => $productName,
                                        'sale_price' => $salePrice,
                                        'old_price' => $oldPrice,
                                        'discount_percent' => $discountPercent,
                                    ]);
                                }
                            } elseif ($oldPrice !== null && $oldPrice <= $salePrice) {
                                // If old_price <= sale_price, treat as no discount (data error)
                                Log::warning('old_price <= sale_price, clearing discount.', [
                                    'product_name' => $productName,
                                    'sale_price' => $salePrice,
                                    'old_price' => $oldPrice,
                                ]);
                                $savingsAmount = null;
                                $discountPercent = null;
                                $oldPrice = null;
                            }

                            // Phase 1 brand resolution: alias map + fuzzy snap
                            // to an existing Brand + name-scan inference when
                            // OCR printed no brand at all.
                            $canonicalBrand = ProductDataNormalizer::canonicalBrandName(
                                is_string($brandName) && trim($brandName) !== '' ? trim($brandName) : null,
                                $productName,
                            );

                            $brandId = null;

                            if (is_string($canonicalBrand) && trim($canonicalBrand) !== '') {
                                $brandNameTrim = trim($canonicalBrand);
                                $brandSlug = Str::slug($brandNameTrim);

                                if ($brandSlug === '') {
                                    $brandSlug = 'brand-'.Str::lower(Str::ulid()->toString());
                                }

                                try {
                                    $brand = Brand::firstOrCreate(
                                        ['name' => $brandNameTrim],
                                        ['slug' => $this->uniqueBrandSlug($brandSlug)]
                                    );
                                    $brandId = $brand->id;
                                } catch (Throwable $e) {
                                    // Unique-constraint race with a concurrent worker:
                                    // re-query instead of dropping the brand entirely.
                                    try {
                                        $brand = Brand::query()->where('name', $brandNameTrim)->first();
                                        $brandId = $brand?->id;

                                        if ($brandId === null) {
                                            Log::warning('Failed to resolve brand, proceeding without brand.', [
                                                'brand_name' => $brandNameTrim,
                                                'error' => $e->getMessage(),
                                            ]);
                                        }
                                    } catch (Throwable $re) {
                                        Log::warning('Brand re-query failed after create race.', [
                                            'brand_name' => $brandNameTrim,
                                            'error' => $re->getMessage(),
                                        ]);
                                    }
                                }
                            }

                            $slug = Str::slug($productName);

                            if ($slug === '') {
                                $slug = 'product-'.Str::lower(Str::ulid()->toString());
                            }

                            $slug = $slug.'-'.substr(Str::ulid()->toString(), -4);

                            // extra_attributes json
                            $extraJson = null;

                            if (is_array($extraAttributes) && $extraAttributes !== []) {
                                $extraJson = $extraAttributes;
                            } elseif (is_array($extraAttributes) && $extraAttributes === []) {
                                $extraJson = null;
                            }

                            // Empty normalization falls back to the raw product name
                            // so the item never joins the dedup '' bucket.
                            $normalizedName = ArabicNormalizer::normalize($productName) ?: $productName;

                            // Phase 1 in-memory duplicate match across the WHOLE
                            // flyer (not just this page): identical dedup key OR
                            // equal price + ≥82% similar stems. A match ENRICHES
                            // the existing row instead of discarding the new read.
                            $candidate = new FlyerItem([
                                'product_name' => $productName,
                                'sale_price' => $salePrice,
                            ]);

                            $matchItem = null;

                            foreach ($flyerItems as $existingItem) {
                                if (ProductDataNormalizer::areSameProduct($existingItem, $candidate)) {
                                    $matchItem = $existingItem;

                                    break;
                                }
                            }

                            if ($matchItem !== null) {
                                Log::info('Duplicate product matched within flyer, enriching existing row.', [
                                    'flyer_id' => $flyer->id,
                                    'page_number' => $this->pageNumber,
                                    'product_name' => $productName,
                                    'sale_price' => $salePrice,
                                    'matched_item_id' => $matchItem->id,
                                ]);

                                $enriched = false;

                                // Missing old_price → adopt the new read and
                                // recompute savings/discount (guards: old must
                                // exceed sale; >90% is a superscript misread).
                                if (($matchItem->old_price === null || (float) $matchItem->old_price <= 0)
                                    && $oldPrice !== null && $oldPrice > $salePrice) {
                                    $discount = round((($oldPrice - $salePrice) / $oldPrice) * 100, 2);

                                    if ($discount <= 90) {
                                        $matchItem->old_price = $oldPrice;
                                        $matchItem->savings_amount = round($oldPrice - $salePrice, 2);
                                        $matchItem->discount_percent = $discount;
                                        $enriched = true;
                                    }
                                }

                                if ($matchItem->brand_id === null && $brandId !== null) {
                                    $matchItem->brand_id = $brandId;
                                    $enriched = true;
                                }

                                if (trim((string) $matchItem->unit) === ''
                                    && is_string($unit) && trim($unit) !== '') {
                                    $matchItem->unit = trim($unit);
                                    $enriched = true;
                                }

                                if (blank($matchItem->category) && $category !== null) {
                                    $matchItem->category = $category;
                                    $enriched = true;
                                }

                                if ($enriched) {
                                    $matchItem->saveQuietly();
                                }

                                continue;
                            }

                            $newItem = FlyerItem::create([
                                'flyer_id' => $flyer->id,
                                'flyer_page_id' => $flyerPage->id,
                                'brand_id' => $brandId,
                                'product_name' => $productName,
                                'slug' => $slug,
                                'normalized_name' => $normalizedName,
                                'sale_price' => $salePrice,
                                'old_price' => $oldPrice,
                                'savings_amount' => $savingsAmount,
                                'discount_percent' => $discountPercent,
                                'unit' => is_string($unit) && trim($unit) !== '' ? trim($unit) : null,
                                'category' => $category,
                                'bundle_condition' => is_string($bundleCondition) && trim($bundleCondition) !== '' ? trim($bundleCondition) : null,
                                'extra_attributes' => $extraJson,
                                'is_featured' => false,
                            ]);

                            // Register in the in-memory cache so duplicates INSIDE
                            // the same page also match (areSameProduct sees them).
                            $flyerItems[] = $newItem;
                        } catch (Throwable $e) {
                            Log::error('Failed to insert flyer_item.', [
                                'flyer_id' => $flyer->id,
                                'product_name' => $raw['product_name'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
            });

            // Replaced image (retry with a different R2 key): reap the OLD
            // object only AFTER the new path is durably committed, async, so
            // no network I/O ever sits inside a SQLite transaction.
            if (is_string($replacedOldPath) && $replacedOldPath !== '') {
                $oldPath = $replacedOldPath;

                dispatch(static function () use ($oldPath): void {
                    try {
                        Storage::disk('r2')->delete($oldPath);
                    } catch (Throwable $e) {
                        Log::warning('ProcessSinglePageJob: replaced image R2 delete failed.', [
                            'path' => $oldPath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                })->afterCommit();
            }

            // Replaced/stale cover thumbnail (cover retry or failed thumb
            // regeneration): reap the OLD thumb object the same way.
            if (is_string($replacedOldThumbPath) && $replacedOldThumbPath !== '') {
                $oldThumbPath = $replacedOldThumbPath;

                dispatch(static function () use ($oldThumbPath): void {
                    try {
                        Storage::disk('r2')->delete($oldThumbPath);
                    } catch (Throwable $e) {
                        Log::warning('ProcessSinglePageJob: replaced thumb R2 delete failed.', [
                            'path' => $oldThumbPath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                })->afterCommit();
            }

            Log::info('ProcessSinglePageJob completed successfully.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'items_extracted' => count($items),
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessSinglePageJob processPage failed.', [
                'flyer_id' => $this->flyerId,
                'page_number' => $this->pageNumber,
                'image_url' => $this->imageUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Read the actual pixel dimensions from the converted binary — never
     * hardcoded. Falls back to the column defaults when unreadable (PDF path).
     *
     * @return array{0: int, 1: int}
     */
    private function pageDimensions(?string $binary): array
    {
        if (is_string($binary) && $binary !== '') {
            $info = @getimagesizefromstring($binary);

            if (is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0) {
                return [(int) $info[0], (int) $info[1]];
            }
        }

        return [1200, 1600];
    }

    /**
     * Download raw image binary with defensive timeout and a hard 20MB cap.
     * The cap aborts the transfer inside Guzzle's on_headers hook (before the
     * body streams into worker memory) and is re-checked after download for
     * chunked responses lacking Content-Length.
     *
     * @throws \RuntimeException
     */
    private function downloadRawImage(string $url): string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Magalatna-ProcessSinglePage/1.0',
                    'Accept' => 'image/*',
                ])
                ->withOptions([
                    // Redirect SSRF guard: every 30x hop is re-validated — a
                    // redirect must never pivot into loopback/private/reserved
                    // IP ranges or internal hostnames.
                    'allow_redirects' => [
                        'max' => 5,
                        'on_redirect' => static function ($request, $response, $uri): void {
                            PublicUrlGuard::assertPublicHttpUrl((string) $uri);
                        },
                    ],
                    'http_errors' => false,
                    'on_headers' => static function (ResponseInterface $headers) use ($url): void {
                        $contentLength = (int) ($headers->getHeaderLine('Content-Length') ?: 0);

                        if ($contentLength > self::MAX_IMAGE_BYTES) {
                            throw new \RuntimeException('Image exceeds maximum allowed size (Content-Length): '.$url);
                        }
                    },
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::error('Failed to download raw image for page.', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                throw new \RuntimeException('Failed to download image: HTTP '.$response->status().' for '.$url);
            }

            $body = $response->body();

            if ($body === '') {
                throw new \RuntimeException('Downloaded image is empty: '.$url);
            }

            if (strlen($body) > self::MAX_IMAGE_BYTES) {
                throw new \RuntimeException('Image exceeds maximum allowed size: '.$url);
            }

            return $body;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Exception downloading raw image.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to download image: '.$e->getMessage(), 0, $e);
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

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessSinglePageJob permanently failed.', [
            'flyer_id' => $this->flyerId,
            'page_number' => $this->pageNumber,
            'image_url' => $this->imageUrl,
            'error' => $exception->getMessage(),
        ]);
    }
}
