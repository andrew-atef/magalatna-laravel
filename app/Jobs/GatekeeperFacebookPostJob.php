<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use App\Services\FlyerSlugService;
use App\Services\GeminiVisionService;
use App\Support\ArabicDateHelper;
use App\Support\FacebookMediaHelper;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    public ?int $rawFacebookPostId = null;

    /**
     * @param  list<string>  $imageUrls
     */
    public function __construct(
        public readonly string $retailerSlug,
        public readonly string $facebookPostId,
        public readonly string $postText,
        public readonly array $imageUrls,
        public readonly string $publishedAt,
        ?int $rawFacebookPostId = null,
    ) {
        $this->rawFacebookPostId = $rawFacebookPostId;
    }

    public function __wakeup(): void
    {
        if (! isset($this->rawFacebookPostId)) {
            $this->rawFacebookPostId = null;
        }
    }

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
            if ($count >= 3) {
                $sampleImageUrls[] = $this->imageUrls[$count - 1]; // Last Page
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

                    if ($this->rawFacebookPostId !== null) {
                        RawFacebookPost::where('id', $this->rawFacebookPostId)->update([
                            'status' => 'rejected',
                            'rejection_reason' => 'العرض منتهي الصلاحية بتوقيت القاهرة (' . $validUntilCarbon->toDateString() . ')',
                        ]);
                    }

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

            // === ATOMIC INGEST LOCK: serialize all ingest for this retailer ===
            // Prevents concurrent workers from both seeing existingFlyer === null
            // and creating duplicate -part-2 flyers for the same period.
            $lockKey = 'flyer_ingest_lock_'.$retailer->id;
            $fromStr = $validFromCarbon->toDateString();
            $untilStr = $validUntilCarbon->toDateString();

            try {
                Cache::lock($lockKey, 60)->block(30, function () use ($gemini, $slugService, $retailer, $validFromCarbon, $validUntilCarbon, $governorates, $flyerTitle, $count, $fromStr, $untilStr): void {
                    // 1. EXACT range match only: same retailer + identical period.
                    // Partially-overlapping but distinct flyers are NEVER swallowed
                    // into one another here; the slug-base check below is the only
                    // secondary signal, and creation stays the default otherwise.
                    $existingFlyer = Flyer::where('retailer_id', $retailer->id)
                        ->where('valid_from', $fromStr)
                        ->where('valid_until', $untilStr)
                        ->whereIn('status', [FlyerStatus::Draft, FlyerStatus::PendingReview, FlyerStatus::Published])
                        ->orderBy('id')
                        ->first();

                    // 2. Also check if the generated slug base already exists (catches renamed duplicates)
                    $baseSlug = $slugService->generate($retailer->slug, $fromStr, $untilStr);
                    if ($existingFlyer === null) {
                        // Strip any -part-N suffix the service may have appended, then fuzzy-match
                        $canonicalBase = (string) preg_replace('/-part-\d+$/', '', $baseSlug);
                        $existingFlyer = Flyer::where('retailer_id', $retailer->id)
                            ->where('slug', 'like', $canonicalBase.'%')
                            ->orderBy('id')
                            ->first();
                        if ($existingFlyer !== null) {
                            Log::info('[STRICT-MERGE] Matched existing flyer by slug base.', [
                                'existing_flyer_id' => $existingFlyer->id,
                                'base_slug' => $canonicalBase,
                                'retailer_id' => $retailer->id,
                            ]);
                        }
                    }

                    // 3. STRICT MERGE: If ANY existing flyer matched, merge unique images into it. NEVER create -part-2!
                    if ($existingFlyer !== null) {
                        // Immutable photo-signature dedup: Facebook serves identical photos from
                        // rotating edge hosts (scontent-cdg/mrs/prg...), so full-URL comparison
                        // misses duplicates. Match on the host-independent photo signature.
                        $existingRawSignatures = RawFacebookPost::where('flyer_id', $existingFlyer->id)
                            ->pluck('image_urls')
                            ->flatten()
                            ->filter()
                            ->map(fn (mixed $u) => FacebookMediaHelper::extractPhotoSignature((string) $u))
                            ->unique()
                            ->all();

                        // Filter incoming URLs by signature; also collapse intra-batch
                        // host-varied duplicates of the same photo within this post.
                        $seenSignatures = $existingRawSignatures;
                        $filteredImageUrls = [];
                        foreach ($this->imageUrls as $url) {
                            $sig = FacebookMediaHelper::extractPhotoSignature((string) $url);
                            if (in_array($sig, $seenSignatures, true)) {
                                continue;
                            }
                            $seenSignatures[] = $sig;
                            $filteredImageUrls[] = (string) $url;
                        }
                        $filteredImageUrls = array_values($filteredImageUrls);

                        if ($filteredImageUrls === []) {
                            Log::info('[CONSOLIDATION] Zero new unique photo signatures to merge, skipping.', [
                                'existing_flyer_id' => $existingFlyer->id,
                                'retailer_id' => $retailer->id,
                            ]);

                            if ($this->rawFacebookPostId !== null) {
                                RawFacebookPost::where('id', $this->rawFacebookPostId)->update([
                                    'flyer_id' => $existingFlyer->id,
                                    'status' => 'rejected',
                                    'rejection_reason' => 'تم التخطي: جميع صور المنشور مدمجة بالفعل في المجلة #' . $existingFlyer->id,
                                ]);
                            }

                            return;
                        }

                $maxDbPage = (int) ($existingFlyer->pages()->max('page_number') ?? 0);
                $startPageNumber = max((int) $existingFlyer->total_pages, $maxDbPage);
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
                $existingFlyer->saveQuietly();

                $this->updateRawPostFlyer($existingFlyer->id);

                $this->dispatchPageProcessingBatch(
                    flyerId: $existingFlyer->id,
                    imageUrls: $filteredImageUrls,
                    startPageNumber: $startPageNumber,
                    batchName: 'flyer-'.$existingFlyer->id.'-'.$this->facebookPostId.'-consolidated',
                );

                Log::info('Gatekeeper fan-out dispatched (consolidated).', [
                    'flyer_id' => $existingFlyer->id,
                    'jobs_count' => $newPagesCount,
                ]);

                return;
            }

            // 4. Create new flyer ONLY if zero existing flyers matched.
            // $baseSlug was already generated (and uniqueness-checked) inside the lock.
            $slug = $baseSlug;

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

            $this->dispatchPageProcessingBatch(
                flyerId: $flyer->id,
                imageUrls: $this->imageUrls,
                startPageNumber: 0,
                batchName: 'flyer-'.$flyer->id.'-'.$this->facebookPostId,
            );

            Log::info('Gatekeeper fan-out dispatched.', [
                'flyer_id' => $flyer->id,
                'jobs_count' => count($this->imageUrls),
            ]);
                }); // end Cache::lock()->block()
            } catch (LockTimeoutException $e) {
                Log::warning('Could not acquire flyer ingest lock, releasing job for retry.', [
                    'retailer_slug' => $this->retailerSlug,
                    'facebook_post_id' => $this->facebookPostId,
                    'lock_key' => $lockKey,
                    'error' => $e->getMessage(),
                ]);

                $this->release(15);

                return;
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'rate limited')) {
                Log::warning('Gatekeeper hit Gemini 429 rate limit, releasing job back to queue for 30s.', [
                    'facebook_post_id' => $this->facebookPostId,
                    'attempt' => $this->attempts(),
                ]);
                $this->release(30);

                return;
            }

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

    /**
     * Build ProcessSinglePageJobs and dispatch them as one batch.
     *
     * The then() callback recounts pages straight from the DB (O(1) aggregate —
     * pages may have merged further while jobs ran), refreshes BLUF/editorial,
     * and on auto-publish fires async IndexNow ping + Cloudflare purge dispatches
     * (no synchronous network inside the worker).
     *
     * @param  list<string>  $imageUrls
     */
    private function dispatchPageProcessingBatch(int $flyerId, array $imageUrls, int $startPageNumber, string $batchName): void
    {
        $jobs = [];
        foreach (array_values($imageUrls) as $index => $imageUrl) {
            $jobs[] = new ProcessSinglePageJob(
                flyerId: $flyerId,
                imageUrl: (string) $imageUrl,
                pageNumber: $startPageNumber + $index + 1,
            );
        }

        Bus::batch($jobs)
            ->name($batchName)
            ->allowFailures()
            ->then(static function (Batch $batch) use ($flyerId): void {
                try {
                    $flyer = Flyer::find($flyerId);

                    if ($flyer === null) {
                        Log::error('Flyer not found in batch then callback.', ['flyer_id' => $flyerId]);

                        return;
                    }

                    // O(1) recount from the DB — never trust a stale in-memory counter.
                    $flyer->total_pages = (int) $flyer->pages()->count();

                    $autoPublish = (bool) config('app.auto_publish_flyers', true);
                    if ($flyer->status !== FlyerStatus::Published) {
                        $flyer->status = $autoPublish ? FlyerStatus::Published : FlyerStatus::PendingReview;
                    }

                    $flyer->bluf_summary = self::buildBlufSummary($flyer);
                    $flyer->editorial_overview = self::buildEditorialOverview($flyer);
                    // Quiet save: FlyerObserver stays silent so this callback's explicit
                    // purge below remains the SINGLE purge authority (no observer cascade).
                    $flyer->saveQuietly();

                    Log::info($autoPublish ? 'Flyer auto-published with BLUF.' : 'Flyer merged and pending_review with BLUF.', [
                        'flyer_id' => $flyer->id,
                        'batch_id' => $batch->id,
                        'status' => $flyer->status->value,
                    ]);

                    if ($autoPublish) {
                        try {
                            // Single authority: PurgeCloudflareCacheJob purges the Edge
                            // AND fans out PingIndexNowJob internally — never dispatch
                            // PingIndexNowJob alongside it (would double-ping).
                            $flyerUrl = route('flyers.show', $flyer->slug);
                            PurgeCloudflareCacheJob::dispatch([$flyerUrl]);
                        } catch (Throwable $e) {
                            Log::warning('Failed to dispatch post-publish purge.', [
                                'flyer_id' => $flyer->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::error('Failed in batch then() for flyer.', [
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
            $dayName = ArabicDateHelper::dayName($validFrom);
            $monthName = ArabicDateHelper::monthName((int) $validFrom->month);
            $title = sprintf('عروض %s %s %d %s %d | عرض اليوم الواحد', $retailerName, $dayName, $validFrom->day, $monthName, $validFrom->year);
        } else {
            $fromDay = $validFrom->day;
            $toDay = $validUntil->day;
            $fromMonth = ArabicDateHelper::monthName((int) $validFrom->month);
            $toMonth = ArabicDateHelper::monthName((int) $validUntil->month);
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

    public static function buildBlufSummary(Flyer $flyer): string
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
                $untilText = ArabicDateHelper::formatArabicDate($untilCarbon);
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

    public static function buildEditorialOverview(Flyer $flyer): string
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
                    $from = ArabicDateHelper::formatArabicDate($fromCarbon);
                    $until = ArabicDateHelper::formatArabicDate($untilCarbon);
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
