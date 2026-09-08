<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use App\Services\FlyerSlugService;
use App\Services\GeminiVisionService;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GatekeeperFacebookPostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    /**
     * @param  list<string>  $imageUrls
     */
    public function __construct(
        public readonly string $retailerSlug,
        public readonly string $facebookPostId,
        public readonly string $postText,
        public readonly array $imageUrls,
        public readonly string $publishedAt,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeminiVisionService $gemini, FlyerSlugService $slugService): void
    {
        try {
            Log::info('Gatekeeper started.', [
                'retailer_slug' => $this->retailerSlug,
                'facebook_post_id' => $this->facebookPostId,
                'image_count' => count($this->imageUrls),
            ]);

            $firstImageUrl = $this->imageUrls[0] ?? null;

            // Phase 1: Classification via Gemini 2.5 Flash
            $classification = $gemini->classifyPost($this->postText, $firstImageUrl);

            $isFlyer = (bool) ($classification['is_flyer'] ?? false);
            $reason = (string) ($classification['reason'] ?? 'N/A');
            $flyerTitle = $classification['flyer_title'] ?? null;
            $validFrom = $classification['valid_from'] ?? null;
            $validUntil = $classification['valid_until'] ?? null;
            $governorates = $classification['applicable_governorates'] ?? [];

            Log::info('Gatekeeper classification result.', [
                'facebook_post_id' => $this->facebookPostId,
                'is_flyer' => $isFlyer,
                'reason' => $reason,
                'valid_until' => $validUntil,
            ]);

            if ($isFlyer === false) {
                Log::info('Discard: not a flyer.', [
                    'facebook_post_id' => $this->facebookPostId,
                    'reason' => $reason,
                ]);

                return;
            }

            // Cairo Negative Temporal Check
            if (is_string($validUntil) && trim($validUntil) !== '') {
                try {
                    $validUntilCarbon = Carbon::parse($validUntil, 'Africa/Cairo')->startOfDay();
                    $todayCairo = Carbon::today('Africa/Cairo');

                    if ($validUntilCarbon->lt($todayCairo)) {
                        Log::warning('Expired flyer', [
                            'facebook_post_id' => $this->facebookPostId,
                            'valid_until' => $validUntil,
                            'today_cairo' => $todayCairo->toDateString(),
                            'reason' => $reason,
                        ]);

                        return;
                    }
                } catch (Throwable $e) {
                    Log::warning('Failed to parse valid_until for temporal check, proceeding as valid.', [
                        'valid_until' => $validUntil,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Resolve retailer
            $retailer = Retailer::where('slug', $this->retailerSlug)->first();

            if ($retailer === null) {
                Log::error('Retailer not found for Gatekeeper job.', [
                    'retailer_slug' => $this->retailerSlug,
                    'facebook_post_id' => $this->facebookPostId,
                ]);

                throw new \RuntimeException('Retailer not found: '.$this->retailerSlug);
            }

            // Normalize dates
            $validFromCarbon = $this->parseDateOrNull($validFrom);
            $validUntilCarbon = $this->parseDateOrNull($validUntil);

            // Fallbacks for dates if null (use published_at + 7 days window)
            if ($validFromCarbon === null || $validUntilCarbon === null) {
                try {
                    $published = Carbon::parse($this->publishedAt, 'Africa/Cairo');
                    $validFromCarbon ??= $published->copy()->startOfDay();
                    $validUntilCarbon ??= $published->copy()->addDays(7)->startOfDay();
                } catch (Throwable $e) {
                    $validFromCarbon ??= Carbon::today('Africa/Cairo');
                    $validUntilCarbon ??= Carbon::today('Africa/Cairo')->addDays(7);
                    Log::warning('Failed to parse published_at for fallback dates.', [
                        'published_at' => $this->publishedAt,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // إنشاء الـ Slug الإنجليزي الأنيق عبر الخدمة
            $slug = $slugService->generate(
                retailerSlug: $retailer->slug,
                validFromDate: $validFromCarbon->toDateString(),
                validUntilDate: $validUntilCarbon->toDateString()
            );

            $title = is_string($flyerTitle) && trim($flyerTitle) !== ''
                ? trim($flyerTitle)
                : "عروض {$retailer->name} - ".$validFromCarbon->format('d/m/Y');

            // Create Flyer in draft status
            $flyer = Flyer::create([
                'retailer_id' => $retailer->id,
                'title' => $title,
                'slug' => $slug,
                'valid_from' => $validFromCarbon->toDateString(),
                'valid_until' => $validUntilCarbon->toDateString(),
                'applicable_governorates' => $governorates === [] ? null : $governorates,
                'status' => FlyerStatus::Draft,
                'total_pages' => count($this->imageUrls),
                'bluf_summary' => null,
            ]);

            Log::info('Flyer created in draft.', [
                'flyer_id' => $flyer->id,
                'slug' => $flyer->slug,
                'title' => $flyer->title,
            ]);

            // Fan-out: Bus::batch of ProcessSinglePageJob per image
            $jobs = [];

            foreach ($this->imageUrls as $index => $imageUrl) {
                $pageNumber = $index + 1;
                $jobs[] = new ProcessSinglePageJob(
                    flyerId: $flyer->id,
                    imageUrl: (string) $imageUrl,
                    pageNumber: $pageNumber,
                );
            }

            $flyerId = $flyer->id;

            Bus::batch($jobs)
                ->name('flyer-'.$flyer->id.'-'.$this->facebookPostId)
                ->allowFailures()
                ->then(static function (Batch $batch) use ($flyerId): void {
                    try {
                        $flyer = Flyer::find($flyerId);

                        if ($flyer === null) {
                            Log::error('Flyer not found in batch then callback.', ['flyer_id' => $flyerId]);

                            return;
                        }

                        // Transition to pending_review
                        $flyer->status = FlyerStatus::PendingReview;

                        // Generate 2-sentence bluf_summary for SEO/GEO (static to avoid $this serialization)
                        $flyer->bluf_summary = self::buildBlufSummary($flyer);

                        $flyer->save();

                        Log::info('Flyer transitioned to pending_review with BLUF.', [
                            'flyer_id' => $flyer->id,
                            'batch_id' => $batch->id,
                            'bluf_summary' => $flyer->bluf_summary,
                        ]);
                    } catch (Throwable $e) {
                        Log::error('Failed in batch then() for flyer pending_review.', [
                            'flyer_id' => $flyerId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })
                ->catch(function (Batch $batch, Throwable $e): void {
                    Log::error('Batch processing caught failure.', [
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($flyerId): void {
                    Log::info('Batch finished.', [
                        'flyer_id' => $flyerId,
                        'batch_id' => $batch->id,
                        'failed_jobs' => $batch->failedJobs,
                    ]);
                })
                ->onQueue('default')
                ->dispatch();

            Log::info('Gatekeeper fan-out dispatched.', [
                'flyer_id' => $flyer->id,
                'jobs_count' => count($jobs),
            ]);
        } catch (Throwable $e) {
            Log::error('GatekeeperFacebookPostJob failed.', [
                'retailer_slug' => $this->retailerSlug,
                'facebook_post_id' => $this->facebookPostId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function parseDateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), 'Africa/Cairo')->startOfDay();
        } catch (Throwable $e) {
            Log::warning('Invalid date parse.', ['value' => $value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function fallbackTitle(string $retailerName): string
    {
        return trim($retailerName).' Offers '.Carbon::today('Africa/Cairo')->format('Y-m-d');
    }

    private function generateUniqueSlug(string $title, string $retailerSlug, string $facebookPostId): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = Str::slug($retailerSlug.'-'.$facebookPostId);
        }

        $suffix = Str::lower(Str::ulid()->toString());
        $suffix = substr($suffix, -6);

        $candidate = $base.'-'.$suffix;

        // Ensure uniqueness with loop
        $counter = 0;
        $slug = $candidate;

        while (Flyer::where('slug', $slug)->exists()) {
            $counter++;
            $slug = $candidate.'-'.$counter;
        }

        return $slug;
    }

    private function generateBlufSummary(Flyer $flyer): string
    {
        return self::buildBlufSummary($flyer);
    }

    private static function buildBlufSummary(Flyer $flyer): string
    {
        try {
            // Try Gemini strict Arabic 2-sentence BLUF first
            try {
                $retailerName = $flyer->retailer?->name ?? $flyer->retailer()->first()?->name ?? 'المتجر';
                $from = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->format('d/m/Y') : (string) $flyer->valid_from;
                $until = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->format('d/m/Y') : (string) $flyer->valid_until;
                $topProducts = $flyer->items()->limit(5)->pluck('product_name')->all();
                $maxDiscount = (float) ($flyer->items()->max('discount_percent') ?? 0);

                /** @var GeminiVisionService $gemini */
                $gemini = app(GeminiVisionService::class);
                $geminiText = $gemini->generateBlufSummary(
                    retailerName: $retailerName,
                    title: $flyer->title,
                    validFrom: $from,
                    validUntil: $until,
                    totalPages: (int) $flyer->total_pages,
                    topProducts: $topProducts,
                    maxDiscount: $maxDiscount
                );

                if (trim($geminiText) !== '' && ! preg_match('/[a-zA-Z]/', $geminiText)) {
                    return $geminiText;
                }
            } catch (Throwable $e) {
                Log::debug('Gemini BLUF generation failed, using fallback.', ['flyer_id' => $flyer->id, 'error' => $e->getMessage()]);
            }

            // Fallback Arabic deterministic - no quotes, with top 3 products for CTR
            $retailerName = $flyer->retailer?->name ?? $flyer->retailer()->first()?->name ?? 'المتجر';
            $title = $flyer->title ?? 'العرض';
            $from = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->format('d/m/Y') : (string) $flyer->valid_from;
            $until = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->format('d/m/Y') : (string) $flyer->valid_until;
            try {
                $from = Carbon::parse($flyer->valid_from)->format('d/m/Y');
                $until = Carbon::parse($flyer->valid_until)->format('d/m/Y');
            } catch (Throwable $e) {
            }
            $pages = $flyer->total_pages ?? 1;
            $count = $flyer->items()->count();
            $maxDiscount = $flyer->items()->max('discount_percent');
            $topProducts = $flyer->items()->limit(3)->pluck('product_name')->filter()->implode('، ');
            $productsPart = $topProducts !== '' ? " وأبرزها {$topProducts}" : '';

            return "تصفح عروض {$retailerName} {$title} السارية في مصر من {$from} حتى {$until} بـ {$pages} صفحات{$productsPart}. تشمل المجلة {$count} عرضاً بتخفيضات تصل إلى ".round((float) ($maxDiscount ?? 0)).'% على أبرز السلع والمستلزمات.';
        } catch (Throwable $e) {
            Log::warning('Failed to generate BLUF summary, using ultimate fallback.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);

            $retailerName = $flyer->retailer?->name ?? 'المتجر';
            $title = $flyer->title ?? 'العرض';

            return "تصفح عروض {$retailerName} {$title} السارية في مصر. تشمل المجلة عروضاً متنوعة بأسعار مخفضة.";
        }
    }
}
