<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlyerStatus;
use App\Jobs\PingIndexNowJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
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
        public readonly ?int $rawFacebookPostId = null,
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

            $count = count($this->imageUrls);
            $sampleImageUrls = [];
            if ($count > 0) {
                $sampleImageUrls[] = $this->imageUrls[0]; // Page 1 (Cover)
            }
            if ($count >= 2) {
                $sampleImageUrls[] = $this->imageUrls[1]; // Page 2 (Crucial for dates & first price tables)
            }
            if ($count >= 4) {
                $sampleImageUrls[] = $this->imageUrls[$count - 1]; // Last Page (Back cover/terms)
            }
            $sampleImageUrls = array_values(array_unique($sampleImageUrls));

            $classification = $gemini->classifyPost($this->postText, $sampleImageUrls, $count);

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

            // Persist AI classification to audit trail
            $this->updateRawPostClassification($classification, $isFlyer, (string) $reason, $validUntil);

            if ($isFlyer === false) {
                Log::info('Discard: not a flyer.', [
                    'facebook_post_id' => $this->facebookPostId,
                    'reason' => $reason,
                ]);

                return;
            }

            if (! is_string($validUntil) || trim((string) $validUntil) === '') {
                Log::warning('Discard: flyer without explicit valid_until date.', [
                    'facebook_post_id' => $this->facebookPostId,
                    'reason' => $reason,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                ]);

                return;
            }

            $validFromCarbon = $this->parseDateOrNull($validFrom);
            $validUntilCarbon = $this->parseDateOrNull($validUntil);

            if ($validUntilCarbon === null) {
                Log::warning('Discard: flyer without parseable valid_until date.', [
                    'facebook_post_id' => $this->facebookPostId,
                    'valid_until' => $validUntil,
                ]);

                return;
            }

            if ($validFromCarbon === null) {
                $validFromCarbon = $validUntilCarbon->copy();
            }

            try {
                $todayCairo = Carbon::today('Africa/Cairo');

                if ($validUntilCarbon->lt($todayCairo)) {
                    Log::warning('Expired flyer', [
                        'facebook_post_id' => $this->facebookPostId,
                        'valid_until' => $validUntilCarbon->toDateString(),
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

            $retailer = Retailer::where('slug', $this->retailerSlug)->first();

            if ($retailer === null) {
                Log::error('Retailer not found for Gatekeeper job.', [
                    'retailer_slug' => $this->retailerSlug,
                    'facebook_post_id' => $this->facebookPostId,
                ]);

                throw new \RuntimeException('Retailer not found: '.$this->retailerSlug);
            }

            // === CONSOLIDATION CHECK: Merge same-period posts instead of fragmenting ===
            $existingFlyer = Flyer::where('retailer_id', $retailer->id)
                ->where('valid_from', $validFromCarbon->toDateString())
                ->where('valid_until', $validUntilCarbon->toDateString())
                ->whereIn('status', [FlyerStatus::Draft, FlyerStatus::PendingReview, FlyerStatus::Published])
                ->first();

            if ($existingFlyer !== null) {
                $existingPageUrls = $existingFlyer->pages()->pluck('image_path')->all();
                $filteredImageUrls = array_values(array_filter($this->imageUrls, function (string $url) use ($existingPageUrls): bool {
                    $basename = basename((string) parse_url($url, PHP_URL_PATH));
                    foreach ($existingPageUrls as $existing) {
                        if ($url === $existing || basename((string) $existing) === $basename) {
                            return false;
                        }
                    }
                    return true;
                }));
                $filteredImageUrls = array_values(array_unique($filteredImageUrls));

                if ($filteredImageUrls === []) {
                    Log::info('[CONSOLIDATION] No new unique pages to merge, skipping.', [
                        'existing_flyer_id' => $existingFlyer->id,
                        'existing_slug' => $existingFlyer->slug,
                        'retailer_id' => $retailer->id,
                    ]);

                    return;
                }

                $startPageNumber = (int) ($existingFlyer->pages()->max('page_number') ?? 0);
                $newPagesCount = count($filteredImageUrls);

                Log::info('[CONSOLIDATION] Merging ' . $newPagesCount . ' new pages into existing flyer #' . $existingFlyer->id . '.', [
                    'existing_flyer_id' => $existingFlyer->id,
                    'existing_slug' => $existingFlyer->slug,
                    'new_pages' => $newPagesCount,
                    'start_page' => $startPageNumber + 1,
                    'retailer_id' => $retailer->id,
                    'valid_from' => $validFromCarbon->toDateString(),
                    'valid_until' => $validUntilCarbon->toDateString(),
                ]);

                $existingFlyer->total_pages = (int) $existingFlyer->total_pages + $newPagesCount;
                $existingFlyer->save();

                $this->updateRawPostFlyer($existingFlyer->id);

                $jobs = [];
                foreach ($filteredImageUrls as $index => $imageUrl) {
                    $pageNumber = $startPageNumber + $index + 1;
                    $jobs[] = new ProcessSinglePageJob(
                        flyerId: $existingFlyer->id,
                        imageUrl: (string) $imageUrl,
                        pageNumber: $pageNumber,
                    );
                }

                $flyerId = $existingFlyer->id;

                Bus::batch($jobs)
                    ->name('flyer-'.$existingFlyer->id.'-'.$this->facebookPostId.'-consolidated')
                    ->allowFailures()
                    ->then(static function (Batch $batch) use ($flyerId): void {
                        try {
                            $flyer = Flyer::find($flyerId);

                            if ($flyer === null) {
                                Log::error('Flyer not found in consolidated batch then callback.', ['flyer_id' => $flyerId]);

                                return;
                            }

                            $autoPublish = (bool) config('app.auto_publish_flyers', true);
                            if ($flyer->status !== FlyerStatus::Published) {
                                $flyer->status = $autoPublish ? FlyerStatus::Published : FlyerStatus::PendingReview;
                            }

                            $flyer->bluf_summary = self::buildBlufSummary($flyer);
                            $flyer->editorial_overview = self::buildEditorialOverview($flyer);
                            $flyer->save();

                            Log::info($autoPublish ? '[CONSOLIDATION] Flyer auto-published with merged BLUF.' : '[CONSOLIDATION] Flyer merged and pending_review with BLUF.', [
                                'flyer_id' => $flyer->id,
                                'batch_id' => $batch->id,
                                'status' => $flyer->status->value,
                            ]);

                            if ($autoPublish) {
                                try {
                                    PingIndexNowJob::dispatch(route('flyers.show', $flyer->slug));
                                } catch (Throwable $e) {
                                    Log::warning('Failed to dispatch IndexNow after consolidation.', [
                                        'flyer_id' => $flyer->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        } catch (Throwable $e) {
                            Log::error('Failed in consolidated batch then() for flyer.', [
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

                Log::info('Gatekeeper fan-out dispatched (consolidated).', [
                    'flyer_id' => $existingFlyer->id,
                    'jobs_count' => count($jobs),
                ]);

                return;
            }

            // Normal new flyer creation
            $slug = $slugService->generate(
                retailerSlug: $retailer->slug,
                validFromDate: $validFromCarbon->toDateString(),
                validUntilDate: $validUntilCarbon->toDateString()
            );

            $title = $this->formatTitle(
                retailerName: $retailer->name,
                validFrom: $validFromCarbon,
                validUntil: $validUntilCarbon,
                rawTitle: is_string($flyerTitle) ? trim($flyerTitle) : null
            );

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

            $this->updateRawPostFlyer($flyer->id);

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

                        $autoPublish = (bool) config('app.auto_publish_flyers', true);
                        $flyer->status = $autoPublish ? FlyerStatus::Published : FlyerStatus::PendingReview;

                        $flyer->bluf_summary = self::buildBlufSummary($flyer);
                        $flyer->editorial_overview = self::buildEditorialOverview($flyer);

                        $flyer->save();

                        Log::info($autoPublish ? 'Flyer auto-published with BLUF.' : 'Flyer transitioned to pending_review with BLUF.', [
                            'flyer_id' => $flyer->id,
                            'batch_id' => $batch->id,
                            'status' => $flyer->status->value,
                            'bluf_summary' => $flyer->bluf_summary,
                        ]);

                        if ($autoPublish) {
                            try {
                                PingIndexNowJob::dispatch(route('flyers.show', $flyer->slug));
                            } catch (Throwable $e) {
                                Log::warning('Failed to dispatch IndexNow after auto-publish.', [
                                    'flyer_id' => $flyer->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    } catch (Throwable $e) {
                        Log::error('Failed in batch then() for flyer publish.', [
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

    private function updateRawPostClassification(array $classification, bool $isFlyer, string $reason, ?string $validUntil): void
    {
        if ($this->rawFacebookPostId === null) {
            return;
        }

        try {
            $rawPost = RawFacebookPost::find($this->rawFacebookPostId);
            if ($rawPost === null) {
                return;
            }

            $status = ($isFlyer && ! empty($validUntil) && trim((string) $validUntil) !== '') ? 'accepted' : 'rejected';

            $rawPost->update([
                'ai_classification' => $classification,
                'rejection_reason' => $reason,
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to update RawFacebookPost classification.', [
                'raw_post_id' => $this->rawFacebookPostId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateRawPostFlyer(int $flyerId): void
    {
        if ($this->rawFacebookPostId === null) {
            return;
        }

        try {
            $rawPost = RawFacebookPost::find($this->rawFacebookPostId);
            if ($rawPost !== null) {
                $rawPost->update(['flyer_id' => $flyerId]);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to update RawFacebookPost flyer_id.', [
                'raw_post_id' => $this->rawFacebookPostId,
                'flyer_id' => $flyerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fallbackTitle(string $retailerName): string
    {
        return trim($retailerName).' Offers '.Carbon::today('Africa/Cairo')->format('Y-m-d');
    }

    private function arabicMonthName(int $month): string
    {
        return match ($month) {
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
            default => 'يناير',
        };
    }

    private function arabicDayName(Carbon $date): string
    {
        return match ((int) $date->dayOfWeek) {
            0 => 'الأحد',
            1 => 'الإثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
            default => $date->locale('ar')->isoFormat('dddd'),
        };
    }

    private function formatTitle(string $retailerName, Carbon $validFrom, Carbon $validUntil, ?string $rawTitle): string
    {
        $rawTitle = is_string($rawTitle) ? trim($rawTitle) : '';

        $isSingleDay = $validFrom->isSameDay($validUntil);
        $dayNamePattern = '(?:الأحد|الإثنين|الثلاثاء|الأربعاء|الخميس|الجمعة|السبت)';
        $monthPattern = '(?:يناير|فبراير|مارس|أبريل|مايو|يونيو|يوليو|أغسطس|سبتمبر|أكتوبر|نوفمبر|ديسمبر)';

        if ($isSingleDay) {
            $pattern = "/^عروض\s+.+\s+{$dayNamePattern}\s+\d{1,2}\s+{$monthPattern}\s+20\d{2}\s*\|\s*عرض اليوم الواحد$/u";
        } else {
            $pattern = "/^عروض\s+.+\s+من\s+\d{1,2}\s+حتى\s+\d{1,2}\s+{$monthPattern}\s+20\d{2}\s*\|/u";
            if ($validFrom->month !== $validUntil->month) {
                $pattern = "/^عروض\s+.+\s+من\s+\d{1,2}\s+{$monthPattern}\s+حتى\s+\d{1,2}\s+{$monthPattern}\s+20\d{2}\s*\|/u";
            }
        }

        $isWellFormed = $rawTitle !== '' && mb_strlen($rawTitle) >= 20 && mb_strlen($rawTitle) <= 70 && preg_match($pattern, $rawTitle);

        if ($isWellFormed) {
            return $rawTitle;
        }

        $theme = 'مجلة العروض والتوفير';
        if (str_contains($rawTitle, '|')) {
            $parts = explode('|', $rawTitle, 2);
            $candidateTheme = trim($parts[1] ?? '');
            if ($candidateTheme !== '' && mb_strlen($candidateTheme) > 3 && ! preg_match('/^[a-zA-Z\s]+$/', $candidateTheme)) {
                $theme = $candidateTheme;
            }
        } elseif (preg_match('/العودة للمدارس|رمضان|العيد|الصيف|الشتاء|الجمعة البيضاء/u', $rawTitle, $m)) {
            $theme = trim($m[0]);
            if (! str_contains($theme, 'مجلة')) {
                $theme = 'مجلة '.$theme;
            }
        }

        if ($validFrom->isSameDay($validUntil)) {
            $dayName = $this->arabicDayName($validFrom);
            $monthName = $this->arabicMonthName((int) $validFrom->month);
            $title = sprintf('عروض %s %s %d %s %d | عرض اليوم الواحد', $retailerName, $dayName, $validFrom->day, $monthName, $validFrom->year);
        } else {
            $fromDay = $validFrom->day;
            $toDay = $validUntil->day;
            $fromMonth = $this->arabicMonthName((int) $validFrom->month);
            $toMonth = $this->arabicMonthName((int) $validUntil->month);
            $year = $validFrom->year;

            if ($validFrom->month === $validUntil->month && $validFrom->year === $validUntil->year) {
                $title = sprintf('عروض %s من %d حتى %d %s %d | %s', $retailerName, $fromDay, $toDay, $fromMonth, $year, $theme);
            } elseif ($validFrom->year === $validUntil->year) {
                $title = sprintf('عروض %s من %d %s حتى %d %s %d | %s', $retailerName, $fromDay, $fromMonth, $toDay, $toMonth, $year, $theme);
            } else {
                $title = sprintf('عروض %s من %d %s %d حتى %d %s %d | %s', $retailerName, $fromDay, $fromMonth, $validFrom->year, $toDay, $toMonth, $validUntil->year, $theme);
            }
        }

        if (mb_strlen($title) > 65) {
            $title = mb_substr($title, 0, 65);
        }

        return $title;
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

            $titleForBluf = $flyer->title ?? 'العرض';
            $untilCarbon = $flyer->valid_until instanceof Carbon ? $flyer->valid_until : Carbon::parse($flyer->valid_until);
            $untilText = $untilCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
            if (! str_contains($untilText, 'سبتمبر') && ! str_contains($untilText, 'يناير')) {
                $untilText = (new self)->arabicDayName($untilCarbon).' '.$untilCarbon->day.' '.(new self)->arabicMonthName((int) $untilCarbon->month).' '.$untilCarbon->year;
            }
            $maxDiscount = $flyer->items()->max('discount_percent');
            $discount = number_format((float) ($maxDiscount ?? 0), 2, '.', '');
            $discount = rtrim(rtrim($discount, '0'), '.');
            $topProducts = $flyer->items()->limit(5)->pluck('product_name')->filter()->implode('، ');
            $topProducts = $topProducts !== '' ? $topProducts : 'سلع متنوعة';

            return "تصفح {$titleForBluf} الساري في مصر حتى {$untilText}، بخصومات تصل إلى {$discount}%. يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
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

    private static function buildEditorialOverview(Flyer $flyer): string
    {
        try {
            try {
                /** @var GeminiVisionService $gemini */
                $gemini = app(GeminiVisionService::class);
                $text = $gemini->generateEditorialOverview($flyer);
                if (trim($text) !== '' && ! preg_match('/[a-zA-Z]/', $text)) {
                    $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);
                    $text = (string) preg_replace('/(\d+)\s+%/u', '$1%', $text);

                    return $text;
                }
            } catch (Throwable $e) {
                Log::debug('Gemini Editorial generation failed, using fallback.', ['flyer_id' => $flyer->id, 'error' => $e->getMessage()]);
            }

            $retailerName = $flyer->retailer?->name ?? $flyer->retailer()->first()?->name ?? 'المتجر';
            $title = $flyer->title ?? 'العرض';
            $from = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->format('Y-m-d') : (string) $flyer->valid_from;
            $until = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->format('Y-m-d') : (string) $flyer->valid_until;
            try {
                $fromCarbon = Carbon::parse($flyer->valid_from);
                $untilCarbon = Carbon::parse($flyer->valid_until);
                $from = $fromCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                $until = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                if (! preg_match('/[\x{0600}-\x{06FF}]/u', $from)) {
                    $from = $fromCarbon->day.' '.(new self)->arabicMonthName((int) $fromCarbon->month).' '.$fromCarbon->year;
                    $until = $untilCarbon->day.' '.(new self)->arabicMonthName((int) $untilCarbon->month).' '.$untilCarbon->year;
                }
            } catch (Throwable $e) {
            }

            $maxDiscount = $flyer->items()->max('discount_percent');
            $discountRange = $maxDiscount ? number_format((float) $maxDiscount, 2, '.', '').'%' : 'متنوعة';
            $discountRange = rtrim(rtrim($discountRange, '0'), '.');
            $discountRange = str_replace('. ', '.', $discountRange);
            $discountRange = (string) preg_replace('/(\d+)\.\s+(\d)/u', '$1.$2', $discountRange);

            $heroDeals = $flyer->items()->orderByDesc('discount_percent')->limit(4)->get();
            $heroText = $heroDeals->map(function ($item): string {
                $sale = number_format((float) $item->sale_price, 2, '.', '').' ج.م';
                $sale = rtrim(rtrim($sale, '0'), '.');
                $sale = str_replace('. ', '.', $sale);
                $old = $item->old_price ? number_format((float) $item->old_price, 2, '.', '').' ج.م' : null;
                $old = $old ? rtrim(rtrim($old, '0'), '.') : null;
                $old = $old ? str_replace('. ', '.', $old) : null;
                $discount = $item->discount_percent ? round((float) $item->discount_percent).'%' : '';
                if ($old) {
                    return "{$item->product_name} بسعر {$sale} بدلاً من {$old} بخصم {$discount}";
                }

                return "{$item->product_name} بسعر {$sale}";
            })->implode('، ');

            if ($heroDeals->isEmpty()) {
                $heroText = 'سلع متنوعة بأسعار مخفضة';
            }

            $para1 = "تقدم مجلة {$title} من {$retailerName} عروضاً حصرية سارية في مصر من {$from} حتى {$until}، بخصومات {$discountRange} على تشكيلة واسعة من السلع الغذائية والمستلزمات المنزلية.";
            if ($heroDeals->isNotEmpty()) {
                $bullets = $heroDeals->map(function ($item): string {
                    $sale = number_format((float) $item->sale_price, 2, '.', '').' ج.م';
                    $sale = rtrim(rtrim($sale, '0'), '.');
                    $discount = $item->discount_percent ? ' (خصم '.round((float) $item->discount_percent).'%)' : '';
                    $old = $item->old_price ? ' بدلاً من '.rtrim(rtrim(number_format((float) $item->old_price, 2, '.', '').' ج.م', '0'), '.') : '';

                    return "- {$item->product_name} بسعر {$sale}{$old}{$discount}";
                })->implode("\n");
                $para2 = "أبرز الصفقات في هذا العدد:\n{$bullets}";
            } else {
                $para2 = "أبرز الصفقات في هذا العدد:\n- سلع غذائية متنوعة بأسعار مخفضة بجميع الفروع\n- منتجات ألبان ومخبوزات بعروض حصرية\n- منظفات ومستلزمات منزلية بخصومات قوية\n- تخفيضات على اللحوم والدواجن الطازجة";
            }

            $text = $para1."\n\n".$para2;
            $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);

            return $text;
        } catch (Throwable $e) {
            Log::warning('Failed to generate editorial overview, using ultimate fallback.', ['flyer_id' => $flyer->id, 'error' => $e->getMessage()]);

            return "تقدم مجلة {$flyer->title} عروضاً مميزة من ".($flyer->retailer?->name ?? 'المتجر').' سارية في مصر. تشمل المجلة تخفيضات على سلع متنوعة بأسعار تنافسية.';
        }
    }
}
