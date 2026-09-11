<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Services\GeminiVisionService;
use App\Services\ImageOptimizerService;
use App\Support\ArabicNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

final class ProcessSinglePageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 180;

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
            // Redis throttle: 15 RPM for Gemini — fallback to direct execution if Redis unavailable
            try {
                $throttle = Redis::throttle('gemini-api-limit')->allow(15)->every(60);

                $throttle->then(
                    function () use ($gemini, $optimizer): void {
                        $this->processPage($gemini, $optimizer);
                    },
                    function (): void {
                        Log::warning('Gemini rate limit hit, releasing job.', [
                            'flyer_id' => $this->flyerId,
                            'page_number' => $this->pageNumber,
                        ]);

                        $this->release(10);
                    }
                );

                return;
            } catch (Throwable $e) {
                Log::debug('Redis throttle unavailable, processing without throttle.', [
                    'flyer_id' => $this->flyerId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Fallback: direct processing
            $this->processPage($gemini, $optimizer);
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
     * Core page processing logic (called inside throttle).
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

            // 1. Download raw image binary (defensive timeout)
            $rawBinary = $this->downloadRawImage($this->imageUrl);

            // 2. Upload optimized WebP to R2 via ImageOptimizerService
            // Use directory flyers/{flyerId}
            $directory = 'flyers/'.$this->flyerId;

            try {
                // Prefer binary-based upload to avoid double download; fallback to URL-based
                $r2Key = $optimizer->processAndUpload($this->imageUrl, $directory);
            } catch (Throwable $e) {
                Log::error('ImageOptimizerService failed, will attempt direct R2 upload fallback.', [
                    'flyer_id' => $this->flyerId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::info('Image uploaded to R2.', [
                'flyer_id' => $this->flyerId,
                'r2_key' => $r2Key,
            ]);

            // 3. Call Gemini Vision OCR (high-density)
            // Use raw binary for OCR to preserve original quality; Gemini handles WebP as well
            $items = $gemini->extractProductsFromPage($rawBinary);

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

            // 4. Insert FlyerPage and FlyerItems within transaction — WITHOUT cascade cache purge
            DB::transaction(function () use ($flyer, $r2Key, $items): void {
                // Upsert FlyerPage (unique flyer_id + page_number) — no observer, safe
                $flyerPage = FlyerPage::updateOrCreate(
                    [
                        'flyer_id' => $flyer->id,
                        'page_number' => $this->pageNumber,
                    ],
                    [
                        'image_path' => $r2Key,
                        'width' => 1200,
                        'height' => 1600,
                    ]
                );

                Log::info('FlyerPage upserted.', [
                    'flyer_page_id' => $flyerPage->id,
                    'flyer_id' => $flyer->id,
                ]);

                // Prevent cascade purge storm: disable FlyerItemObserver touch on every insert
                FlyerItem::withoutEvents(function () use ($flyer, $flyerPage, $items): void {
                    // Clear previous items for this specific page to ensure idempotency on retries
                    FlyerItem::where('flyer_page_id', $flyerPage->id)->delete();

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
                            $oldPrice = isset($raw['old_price']) && $raw['old_price'] !== null ? (float) $raw['old_price'] : null;
                            $unit = $raw['unit'] ?? null;
                            $brandName = $raw['brand_name'] ?? null;
                            $bundleCondition = $raw['bundle_condition'] ?? null;
                            $extraAttributes = $raw['extra_attributes'] ?? null;

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

                            // Brand resolution
                            $brandId = null;

                            if (is_string($brandName) && trim($brandName) !== '') {
                                $brandNameTrim = trim($brandName);
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
                                    Log::warning('Failed to resolve brand, proceeding without brand.', [
                                        'brand_name' => $brandNameTrim,
                                        'error' => $e->getMessage(),
                                    ]);
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

                            FlyerItem::create([
                                'flyer_id' => $flyer->id,
                                'flyer_page_id' => $flyerPage->id,
                                'brand_id' => $brandId,
                                'product_name' => $productName,
                                'slug' => $slug,
                                'normalized_name' => ArabicNormalizer::normalize($productName),
                                'sale_price' => $salePrice,
                                'old_price' => $oldPrice,
                                'savings_amount' => $savingsAmount,
                                'discount_percent' => $discountPercent,
                                'unit' => is_string($unit) && trim($unit) !== '' ? trim($unit) : null,
                                'bundle_condition' => is_string($bundleCondition) && trim($bundleCondition) !== '' ? trim($bundleCondition) : null,
                                'extra_attributes' => $extraJson,
                                'is_featured' => false,
                            ]);
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
     * Download raw image binary with defensive timeout.
     *
     * @throws \RuntimeException
     */
    private function downloadRawImage(string $url): string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'AmanPrice-ProcessSinglePage/1.0',
                    'Accept' => 'image/*',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
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
