<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlyerStatus;
use App\Exceptions\GeminiRateLimitedException;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use App\Services\FlyerSlugService;
use App\Services\GeminiVisionService;
use App\Services\ImageOptimizerService;
use App\Services\ProductDataNormalizer;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GatekeeperFacebookPostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 10;

    /**
     * Temporary waterfall release() loops never count toward permanent
     * failure — only genuinely thrown exceptions do.
     */
    public int $maxExceptions = 3;

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
                try {
                    $publishedCarbon = Carbon::parse($this->publishedAt, 'Africa/Cairo')->startOfDay();
                } catch (Throwable $e) {
                    $publishedCarbon = Carbon::today('Africa/Cairo');
                }
                if (! $publishedCarbon instanceof Carbon) {
                    $publishedCarbon = Carbon::today('Africa/Cairo');
                }
                $validFromCarbon = $publishedCarbon;
                if ($validFromCarbon->gt($validUntilCarbon)) {
                    $validFromCarbon = $validUntilCarbon->copy();
                }
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
                            'rejection_reason' => 'العرض منتهي الصلاحية بتوقيت القاهرة ('.$validUntilCarbon->toDateString().')',
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
                Cache::lock($lockKey, 60)->block(30, function () use ($slugService, $retailer, $validFromCarbon, $validUntilCarbon, $governorates, $flyerTitle, $fromStr, $untilStr): void {
                    // 1. EXACT range match only: same retailer + identical period.
                    // Partially-overlapping but distinct flyers are NEVER swallowed
                    // into one another here; the slug-base check below is the only
                    // secondary signal, and creation stays the default otherwise.
                    // whereDate is REQUIRED: the date cast persists DATETIME
                    // strings ('2026-11-16 00:00:00') but $fromStr is a bare
                    // 'Y-m-d' — a raw equality on SQLite compares STRINGS and
                    // would never match, silently dead-disabling the primary
                    // dedup signal (MySQL coerces, SQLite does not).
                    $existingFlyer = Flyer::where('retailer_id', $retailer->id)
                        ->whereDate('valid_from', $fromStr)
                        ->whereDate('valid_until', $untilStr)
                        ->whereIn('status', [FlyerStatus::Draft, FlyerStatus::PendingReview, FlyerStatus::Published])
                        ->orderBy('id')
                        ->first();

                    // 2. Also check if the generated slug base already exists (catches renamed duplicates)
                    $baseSlug = $slugService->generate($retailer->slug, $fromStr, $untilStr);
                    if ($existingFlyer === null) {
                        // Strip any -part-N suffix the service may have appended, then
                        // match the canonical base EXACTLY or its own -part-N variants.
                        // A bare prefix LIKE "base%" would merge slug "1" into "10";
                        // escaping % _ \ with SQLite's ESCAPE '\' keeps slugs
                        // containing wildcards literal, and the status filter never
                        // merges new posts into EXPIRED flyers.
                        $canonicalBase = (string) preg_replace('/-part-\d+$/', '', $baseSlug);
                        $partPattern = addcslashes($canonicalBase.'-part-', '\\%_');
                        $existingFlyer = Flyer::where('retailer_id', $retailer->id)
                            ->whereIn('status', [FlyerStatus::Draft, FlyerStatus::PendingReview, FlyerStatus::Published])
                            ->where(fn ($q) => $q->where('slug', $canonicalBase)
                                ->orWhereRaw("slug LIKE ? ESCAPE '\\'", [$partPattern.'%']))
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
                        // === [REFRESH] same-post re-ingest guard ===
                        // When THIS raw post is the flyer's ONLY owner, a
                        // re-ingested richer/reshuffled image set must REBUILD
                        // the flyer in place: appending would duplicate pages
                        // the post already owns, creating would spawn -part-2.
                        $flyerRawCount = (int) RawFacebookPost::where('flyer_id', $existingFlyer->id)->count();
                        $samePostOwnsFlyer = $this->rawFacebookPostId !== null
                            && RawFacebookPost::where('flyer_id', $existingFlyer->id)
                                ->where('id', $this->rawFacebookPostId)
                                ->exists();

                        if ($samePostOwnsFlyer && $flyerRawCount === 1) {
                            $this->refreshSinglePostFlyer($existingFlyer);

                            return;
                        }

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
                                    'rejection_reason' => 'تم التخطي: جميع صور المنشور مدمجة بالفعل في المجلة #'.$existingFlyer->id,
                                ]);
                            }

                            return;
                        }

                        $maxDbPage = (int) ($existingFlyer->pages()->max('page_number') ?? 0);
                        $startPageNumber = max((int) $existingFlyer->total_pages, $maxDbPage);
                        $newPagesCount = count($filteredImageUrls);

                        Log::info('[CONSOLIDATION] Merging '.$newPagesCount.' new pages into existing flyer #'.$existingFlyer->id.'.', [
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
        } catch (GeminiRateLimitedException $e) {
            // The whole ordered model waterfall is temporarily throttled —
            // free the worker and retry after the earliest RPM window instead
            // of sleeping in-process.
            Log::warning('Gatekeeper hit Gemini waterfall rate limit, releasing job.', [
                'facebook_post_id' => $this->facebookPostId,
                'retry_after_seconds' => $e->retryAfterSeconds,
                'attempt' => $this->attempts(),
            ]);

            $this->release($e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : 20);

            return;
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

    /**
     * [REFRESH] Rebuild a single-post flyer IN PLACE from the re-ingested
     * payload. ZERO R2 LEAK: every stale page's image_path is captured BEFORE
     * the teardown (afterwards the rows are cascade-deleted and unrecoverable),
     * and the async reap is dispatched ->afterCommit() once the teardown
     * transaction is durably committed — never network I/O inside a transaction.
     */
    private function refreshSinglePostFlyer(Flyer $existingFlyer): void
    {
        $staleR2Paths = $existingFlyer->pages()
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->filter()
            ->values()
            ->all();

        DB::transaction(function () use ($existingFlyer): void {
            // Teardown in ONE transaction: items + pages + total_pages reset.
            $existingFlyer->items()->delete();
            $existingFlyer->pages()->delete();
            $existingFlyer->total_pages = count($this->imageUrls);
            // saveQuietly: the teardown must NOT fire the observer purge; the
            // rebuilt batch's audited save() in finalizeFlyerBatch is the
            // single purge authority.
            $existingFlyer->saveQuietly();
        });

        if ($staleR2Paths !== []) {
            dispatch(static function () use ($staleR2Paths): void {
                try {
                    Storage::disk('r2')->delete($staleR2Paths);
                } catch (Throwable $e) {
                    Log::warning('[REFRESH] Stale R2 deletion failed.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterCommit();
        }

        Log::info('[REFRESH] Rebuilding flyer in place from the refreshed payload.', [
            'flyer_id' => $existingFlyer->id,
            'slug' => $existingFlyer->slug,
            'pages' => count($this->imageUrls),
            'reaped_r2_objects' => count($staleR2Paths),
        ]);

        $this->dispatchPageProcessingBatch(
            flyerId: $existingFlyer->id,
            imageUrls: $this->imageUrls,
            startPageNumber: 0,
            batchName: 'flyer-'.$existingFlyer->id.'-'.$this->facebookPostId.'-refresh',
        );
    }

    /**
     * Build ProcessSinglePageJobs and dispatch them as one batch whose then()
     * callback funnels into the shared finalizeFlyerBatch() helper.
     *
     * @param  list<string>  $imageUrls
     */
    private function dispatchPageProcessingBatch(int $flyerId, array $imageUrls, int $startPageNumber, string $batchName, bool $deleteOnZeroItems = true): void
    {
        self::dispatchProcessingBatch(
            flyerId: $flyerId,
            imageUrls: $imageUrls,
            startPageNumber: $startPageNumber,
            batchName: $batchName,
            rawPostId: $this->rawFacebookPostId,
            deleteOnZeroItems: $deleteOnZeroItems,
        );
    }

    /**
     * Public static batch dispatcher shared with the ingest controller's
     * rebuild/append upgrade paths (RawFacebookIngestController::
     * rebuildFlyerFromPayload / appendPagesToRawFlyer), so every processing
     * path funnels into ONE finalizer: finalizeFlyerBatch().
     *
     * @param  list<string>  $imageUrls
     */
    public static function dispatchProcessingBatch(int $flyerId, array $imageUrls, int $startPageNumber, string $batchName, ?int $rawPostId = null, bool $deleteOnZeroItems = true): void
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
            ->then(static function (Batch $batch) use ($flyerId, $rawPostId, $deleteOnZeroItems): void {
                self::finalizeFlyerBatch($batch, $flyerId, $rawPostId, $deleteOnZeroItems);
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

    /**
     * Single authoritative post-batch finalizer shared by EVERY processing
     * path (fresh ingest, [CONSOLIDATION] append, [REFRESH] rebuild, and the
     * ingest controller's rebuild/append upgrades).
     *
     * 1. Zero-price kill-switch: 0 items with sale_price > 0 → mark the raw
     *    post rejected and delete the flyer via MODEL delete (never bulk
     *    pages()->delete() first — FlyerObserver::deleting captures every R2
     *    path BEFORE the cascade and reaps the objects afterCommit). For
     *    rebuild/upgrade batches ($deleteOnZeroItems === false) the flyer is
     *    kept alive instead: recount + saveQuietly + stand down.
     * 2. [GATEKEEPER_DEDUP]: remove duplicate [normalized_name, sale_price] items.
     * 3. Recount total_pages straight from the DB (O(1) aggregate — pages may
     *    have merged further while the batch ran).
     * 4. Set status per config('app.auto_publish_flyers') and rebuild the
     *    BLUF/editorial copy.
     * 5. Audited save() (NOT saveQuietly): FlyerObserver is the SINGLE
     *    authority for the Cloudflare purge + sitemap/llms cache forget +
     *    IndexNow ping, fired once, afterCommit, with the full canonical URL
     *    set. 'total_pages' is in its wasChanged() list, so refreshes/upgrades
     *    of already-Published flyers always purge the CDN.
     * 6. Logs carry $validItemsCount — never an undefined $itemsCount.
     */
    public static function finalizeFlyerBatch(Batch $batch, int $flyerId, ?int $rawPostId = null, bool $deleteOnZeroItems = true): void
    {
        try {
            $flyer = Flyer::find($flyerId);

            if ($flyer === null) {
                Log::error('Flyer not found in batch then callback.', ['flyer_id' => $flyerId]);

                return;
            }

            // === 1. ZERO-ITEMS / ZERO-PRICE KILL-SWITCH ===
            $validItemsCount = $flyer->items()->where('sale_price', '>', 0)->count();

            if ($validItemsCount === 0) {
                if ($deleteOnZeroItems) {
                    Log::warning('[ABORT_ZERO_PRICE_FLYER] Discarding flyer: 0 products with price > 0.', [
                        'flyer_id' => $flyer->id,
                        'title' => $flyer->title,
                        'retailer' => $flyer->retailer?->slug,
                        'batch_id' => $batch->id,
                    ]);

                    if ($rawPostId !== null) {
                        RawFacebookPost::where('id', $rawPostId)->update([
                            'status' => 'rejected',
                            'rejection_reason' => 'تم إلغاء المجلة وحذفها تلقائياً: الصور لا تحتوي على أي أسعار حقيقية للسلع (جميع الأسعار المستخرجة 0.00 ج.م أو منعدمة).',
                            'flyer_id' => null,
                        ]);
                    }

                    // تنظيف وحذف المجلة: Eloquent delete fires
                    // FlyerObserver::deleting, which captures every page's
                    // R2 image paths BEFORE SQLite's ON DELETE CASCADE
                    // wipes flyer_pages — the queued afterCommit closure
                    // then reaps the R2 objects. Deleting pages()/items()
                    // via bulk queries first would fire zero model events
                    // and orphan every WebP file on R2.
                    $flyer->delete();

                    return; // خروج فوري - لن تُنشر ولن تلوث الموقع
                }

                // Rebuild/upgrade safety: a partial re-extraction may yield
                // zero items while the flyer stays live — never kill a live
                // flyer for an upgrade batch, just recount and stand down.
                Log::warning('[GATEKEEPER_ZERO_ITEMS_STAND_DOWN] Upgrade batch extracted 0 priced items; flyer kept alive.', [
                    'flyer_id' => $flyer->id,
                    'batch_id' => $batch->id,
                ]);

                $flyer->total_pages = (int) $flyer->pages()->count();
                $flyer->saveQuietly();

                return;
            }

            // === 2. [GATEKEEPER_DEDUP] auto item deduplication ===
            $dedupedCount = self::dedupFlyerItems($flyer);

            if ($dedupedCount > 0) {
                Log::info('[GATEKEEPER_DEDUP] Removed duplicate items.', [
                    'flyer_id' => $flyer->id,
                    'removed' => $dedupedCount,
                ]);
            }

            // === 3. O(1) recount from the DB — never trust a stale counter. ===
            $flyer->total_pages = (int) $flyer->pages()->count();

            // === 4. Status + copy rebuild. ===
            $autoPublish = (bool) config('app.auto_publish_flyers', true);
            if ($flyer->status !== FlyerStatus::Published) {
                $flyer->status = $autoPublish ? FlyerStatus::Published : FlyerStatus::PendingReview;
            }

            $flyer->bluf_summary = self::buildBlufSummary($flyer);
            $flyer->editorial_overview = self::buildEditorialOverview($flyer);

            // === 4b. Google Discover 1280x720 hero composite ===
            // Generated BEFORE the audited save: the discover_image_path rides
            // the same save() (one observer purge, one IndexNow ping) and the
            // CDN edge never caches the page without its final og:image.
            self::ensureDiscoverImage($flyer);

            // === 5. Auditable save (NOT saveQuietly): FlyerObserver is the
            // SINGLE authority for the Cloudflare purge + sitemap/llms cache
            // forget + IndexNow ping on publish/refresh. It fires once,
            // afterCommit, with the full canonical URL set — no double purge,
            // no double ping.
            $flyer->save();

            // === 6. Log with $validItemsCount (never undefined $itemsCount). ===
            Log::info($autoPublish ? 'Flyer auto-published with verified items.' : 'Flyer merged and pending_review with verified items.', [
                'flyer_id' => $flyer->id,
                'items_count' => $validItemsCount,
                'deduped_items' => $dedupedCount,
                'batch_id' => $batch->id,
                'status' => $flyer->status->value,
            ]);

            // === 7. Vertical Reel/Short auto-generation ===
            // Dispatched ONLY for verified, priced items (the kill-switch
            // above already returned otherwise) and after the audited save
            // commits. Heavy ffmpeg/Gemini-TTS work lives in its own job so
            // the batch callback stays O(fast).
            if ((bool) config('services.reels.auto_generate', true)) {
                GenerateFlyerReelJob::dispatch($flyer->id)->afterCommit();
            }
        } catch (Throwable $e) {
            Log::error('Failed in batch then() for flyer.', [
                'flyer_id' => $flyerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Google Discover hero composite — generated ONCE per flyer (idempotent:
     * a flyer that already carries discover_image_path is skipped, so retries
     * and rebuild batches never re-encode). Non-fatal: any R2/Intervention
     * failure just logs and leaves the flyer's meta fallback to the cover.
     *
     * Public: shared with RegenerateFlyerContentJob (single authority for the
     * 1280x720 composite — never duplicated).
     */
    public static function ensureDiscoverImage(Flyer $flyer): void
    {
        if (trim((string) $flyer->discover_image_path) !== '') {
            return;
        }

        try {
            $pages = $flyer->pages()
                ->whereIn('page_number', [1, 2])
                ->orderBy('page_number')
                ->get(['id', 'image_path', 'page_number']);

            $coverPage = $pages->firstWhere('page_number', 1) ?? $pages->first();

            if ($coverPage === null || trim((string) $coverPage->image_path) === '') {
                return;
            }

            $page2 = $pages->firstWhere('page_number', 2);

            $optimizer = app(ImageOptimizerService::class);

            $coverBinary = Storage::disk('r2')->get((string) $coverPage->image_path);
            $page2Binary = null;
            if ($page2 !== null && trim((string) $page2->image_path) !== '') {
                try {
                    $page2Binary = Storage::disk('r2')->get((string) $page2->image_path);
                } catch (Throwable $e) {
                    Log::warning('Discover page-2 fetch failed, generating cover-only composite.', [
                        'flyer_id' => $flyer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $discoverBinary = $optimizer->generateDiscoverComposite((string) $coverBinary, $page2Binary);
            $flyer->discover_image_path = $optimizer->storeDiscoverWebp($discoverBinary, 'flyers/'.$flyer->id);

            unset($coverBinary, $page2Binary, $discoverBinary);
            gc_collect_cycles();

            Log::info('Discover composite generated.', [
                'flyer_id' => $flyer->id,
                'discover_image_path' => $flyer->discover_image_path,
            ]);
        } catch (Throwable $e) {
            Log::warning('Discover composite generation failed (non-fatal, page falls back to cover).', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * [GATEKEEPER_DEDUP] Remove duplicate items using the Richest-Row Winner
     * strategy: OCR commonly re-reads the same offer from adjacent tiles AND
     * from the flyer COVER — identical [normalized product stem, sale_price]
     * pairs (or ≥82% similar stems at an equal price) collapse onto the
     * richest row, and missing fields (old_price / brand_id / unit /
     * category) are MERGED onto the survivor before the losers are deleted.
     *
     * Bulk delete is safe here: flyer_items carry no R2 assets, so no model
     * events are needed.
     *
     * @return int number of items removed
     */
    public static function dedupFlyerItems(Flyer $flyer): int
    {
        $items = $flyer->items()->orderBy('id')->get();

        // Nameless rows carry no dedup signal — never collapsed, never merged.
        $deduplicatable = $items->filter(static fn (FlyerItem $item): bool => trim((string) $item->product_name) !== '');

        if ($deduplicatable->count() < 2) {
            return 0;
        }

        /** @var array{kept: list<FlyerItem>, removedIds: list<int>} $result */
        $result = ProductDataNormalizer::deduplicate($deduplicatable->all());

        $removedIds = $result['removedIds'];

        if ($removedIds === []) {
            return 0;
        }

        // Persist survivor enrichments (missing fields merged in memory).
        foreach ($result['kept'] as $survivor) {
            if ($survivor->isDirty()) {
                $survivor->saveQuietly();
            }
        }

        $flyer->items()->whereIn('id', $removedIds)->delete();

        return count($removedIds);
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

        return self::applyTitleLengthCap($title, $retailerName);
    }

    /**
     * 65-char title cap with MINIMAL information loss, in order of
     * preference:
     *  1. Strip English parentheticals "(El Fergany)" from the retailer
     *     name — Latin noise wastes ~12 chars that FULL Arabic words need
     *     in SERPs/sitemap titles;
     *  2. Only if still too long, cut at the LAST SPACE before the cap
     *     (mb_strrpos) so Arabic words are never chopped mid-word
     *     ("| مه", "| تخفيضا");
     *  3. Trim dangling separators/whitespace left by the cut.
     */
    public static function applyTitleLengthCap(string $title, string $retailerName): string
    {
        if (mb_strlen($title) <= 65) {
            return $title;
        }

        $latinlessName = trim((string) preg_replace('/\s*\([^()]*[A-Za-z][^()]*\)/u', '', $retailerName));
        if ($latinlessName !== '' && $latinlessName !== $retailerName) {
            $title = (string) str_replace($retailerName, $latinlessName, $title);
        }

        if (mb_strlen($title) > 65) {
            $title = mb_substr($title, 0, 65);
            $lastSpace = mb_strrpos($title, ' ');
            if ($lastSpace !== false && $lastSpace > 20) {
                $title = mb_substr($title, 0, $lastSpace);
            }
            $title = rtrim($title, " \t|-");
        }

        return $title;
    }

    /**
     * Format a decimal for Arabic copy: "12.00" → "12", "15.50" → "15.5".
     */
    private static function trimDecimal(float $value): string
    {
        $trimmed = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Format an EGP price: trim decimals FIRST, then append the currency —
     * rtrim()ing the finished string would eat the Arabic "ج.م" suffix.
     */
    private static function formatEgp(float $amount): string
    {
        return self::trimDecimal($amount).' ج.م';
    }

    public static function buildBlufSummary(Flyer $flyer): string
    {
        try {
            $titleForBluf = $flyer->title ?? 'العرض';
            // NEVER Carbon::parse('') — an empty/garbage valid_until would resolve
            // to "today" and misstate the offer window. Fall back to a neutral
            // Arabic-formatted current date only when a parseable date exists.
            $untilText = ArabicDateHelper::formatArabicDate(Carbon::now('Africa/Cairo'));
            $untilRaw = $flyer->valid_until;

            if ($untilRaw instanceof Carbon || (is_string($untilRaw) && trim($untilRaw) !== '')) {
                try {
                    $untilCarbon = $untilRaw instanceof Carbon
                        ? $untilRaw->copy()->setTimezone('Africa/Cairo')
                        : Carbon::parse(trim((string) $untilRaw), 'Africa/Cairo');

                    $untilText = $untilCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
                    if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilText)) {
                        $untilText = ArabicDateHelper::formatArabicDate($untilCarbon);
                    }
                } catch (Throwable $e) {
                    Log::debug('BLUF valid_until parse failed, using neutral date.', [
                        'flyer_id' => $flyer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $maxDiscount = round((float) ($flyer->items()->where('sale_price', '>', 0)->max('discount_percent') ?? 0));
            // 0% protection: never publish "بخصومات تصل إلى 0%." — when no real
            // discount was detected, advertise competitive pricing instead.
            $discountPhrase = $maxDiscount > 0
                ? 'بخصومات تصل إلى '.self::trimDecimal((float) $maxDiscount).'%'
                : 'بأسعار تنافسية مخفضة';
            $topProducts = $flyer->items()->where('sale_price', '>', 0)->orderByDesc('discount_percent')->limit(4)->pluck('product_name')->filter()->implode('، ');
            $topProducts = $topProducts !== '' ? $topProducts : 'سلع متنوعة';

            // UPCOMING flyer (published before its start date): "الساري" is
            // factually WRONG until valid_from — Cairo-day comparison, same
            // rule as the Flyer accessors.
            $isUpcoming = false;
            $fromText = '';
            try {
                $fromRawForUpcoming = $flyer->valid_from;
                if ($fromRawForUpcoming instanceof Carbon || (is_string($fromRawForUpcoming) && trim((string) $fromRawForUpcoming) !== '')) {
                    $fromCarbonForUpcoming = $fromRawForUpcoming instanceof Carbon
                        ? $fromRawForUpcoming->copy()->setTimezone('Africa/Cairo')
                        : Carbon::parse(trim((string) $fromRawForUpcoming), 'Africa/Cairo');
                    $isUpcoming = now('Africa/Cairo')->startOfDay()->lt($fromCarbonForUpcoming->copy()->startOfDay());
                    if ($isUpcoming) {
                        $fromText = $fromCarbonForUpcoming->locale('ar')->isoFormat('dddd D MMMM YYYY');
                        if (! preg_match('/[\x{0600}-\x{06FF}]/u', $fromText)) {
                            $fromText = ArabicDateHelper::formatArabicDate($fromCarbonForUpcoming);
                        }
                    }
                }
            } catch (Throwable $e) {
                $isUpcoming = false;
            }

            if ($isUpcoming) {
                return "تصفح {$titleForBluf} يبدأ في مصر يوم {$fromText} وحتى {$untilText}، {$discountPhrase}. يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
            }

            return "تصفح {$titleForBluf} الساري في مصر حتى {$untilText}، {$discountPhrase}. يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
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
            $retailerName = $flyer->retailer?->name ?? $flyer->retailer()->first()?->name ?? 'المتجر';
            $title = $flyer->title ?? 'العرض';
            $from = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->format('Y-m-d') : (string) $flyer->valid_from;
            $until = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->format('Y-m-d') : (string) $flyer->valid_until;
            try {
                // Explicit Cairo tz + null guard: Carbon::parse('') would silently
                // resolve to today and fabricate a wrong offer window.
                $fromRaw = $flyer->valid_from;
                $untilRaw = $flyer->valid_until;

                if ($fromRaw !== null && $untilRaw !== null) {
                    $fromCarbon = $fromRaw instanceof Carbon
                        ? $fromRaw->copy()->setTimezone('Africa/Cairo')
                        : Carbon::parse(trim((string) $fromRaw), 'Africa/Cairo');
                    $untilCarbon = $untilRaw instanceof Carbon
                        ? $untilRaw->copy()->setTimezone('Africa/Cairo')
                        : Carbon::parse(trim((string) $untilRaw), 'Africa/Cairo');

                    $from = $fromCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                    $until = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                    if (! preg_match('/[\x{0600}-\x{06FF}]/u', $from)) {
                        $from = ArabicDateHelper::formatArabicDate($fromCarbon);
                        $until = ArabicDateHelper::formatArabicDate($untilCarbon);
                    }
                }
            } catch (Throwable $e) {
            }

            // UPCOMING flyer (published before its start date): "سارية" is
            // factually WRONG until valid_from — Cairo-day comparison, same
            // rule as the Flyer accessors and the BLUF builder.
            $isUpcoming = false;
            $fromDayName = '';
            try {
                $fromRawForUpcoming = $flyer->valid_from;
                if ($fromRawForUpcoming instanceof Carbon || (is_string($fromRawForUpcoming) && trim((string) $fromRawForUpcoming) !== '')) {
                    $fromCarbonForUpcoming = $fromRawForUpcoming instanceof Carbon
                        ? $fromRawForUpcoming->copy()->setTimezone('Africa/Cairo')
                        : Carbon::parse(trim((string) $fromRawForUpcoming), 'Africa/Cairo');
                    $isUpcoming = now('Africa/Cairo')->startOfDay()->lt($fromCarbonForUpcoming->copy()->startOfDay());
                    if ($isUpcoming) {
                        $fromDayName = $fromCarbonForUpcoming->locale('ar')->isoFormat('dddd');
                        if (! preg_match('/[\x{0600}-\x{06FF}]/u', $fromDayName)) {
                            $fromDayName = ArabicDateHelper::dayName($fromCarbonForUpcoming);
                        }
                    }
                }
            } catch (Throwable $e) {
                $isUpcoming = false;
            }

            $maxDiscount = round((float) ($flyer->items()->where('sale_price', '>', 0)->max('discount_percent') ?? 0));
            // Trim ".00" BEFORE appending the unit — rtrim() on the finished
            // string hits the Arabic "ج.م" suffix and is a no-op.
            $discountRange = $maxDiscount > 0 ? self::trimDecimal((float) $maxDiscount).'%' : 'متنوعة';
            $discountRange = (string) preg_replace('/(\d+)\.\s+(\d)/u', '$1.$2', $discountRange);

            $heroDeals = $flyer->items()->where('sale_price', '>', 0)->orderByDesc('discount_percent')->limit(4)->get();
            $heroText = $heroDeals->map(function ($item): string {
                $sale = self::formatEgp((float) $item->sale_price);
                $old = $item->old_price ? self::formatEgp((float) $item->old_price) : null;
                $discount = $item->discount_percent ? round((float) $item->discount_percent).'%' : '';
                if ($old) {
                    return "{$item->product_name} بسعر {$sale} بدلاً من {$old} بخصم {$discount}";
                }

                return "{$item->product_name} بسعر {$sale}";
            })->implode('، ');

            if ($heroDeals->isEmpty()) {
                $heroText = 'سلع متنوعة بأسعار مخفضة';
            }

            $windowPhrase = $isUpcoming && $fromDayName !== ''
                ? "عروضاً حصرية تبدأ في مصر يوم {$fromDayName} {$from} وحتى {$until}"
                : "عروضاً حصرية سارية في مصر من {$from} حتى {$until}";

            $para1 = "تقدم مجلة {$title} من {$retailerName} {$windowPhrase}، بخصومات {$discountRange} على تشكيلة واسعة من السلع الغذائية والمستلزمات المنزلية.";
            if ($heroDeals->isNotEmpty()) {
                $bullets = $heroDeals->map(function ($item): string {
                    $sale = self::formatEgp((float) $item->sale_price);
                    $discount = $item->discount_percent ? ' (خصم '.round((float) $item->discount_percent).'%)' : '';
                    $old = $item->old_price ? ' بدلاً من '.self::formatEgp((float) $item->old_price) : '';

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
