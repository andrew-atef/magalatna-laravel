<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use App\Support\FacebookMediaHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RawFacebookIngestController extends Controller
{
    /**
     * Handle raw Facebook post ingestion.
     *
     * Validates payload, resolves duplicates/upgrades, dispatches Gatekeeper,
     * returns 202 within 100ms.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Tracks the 24h fingerprint claim: every failure path releases it so
        // transient errors never lock out valid retries for 24h.
        $fingerprint = null;

        try {
            $validated = $request->validate([
                'retailer_slug' => ['required', 'string', 'max:255', 'exists:retailers,slug'],
                'facebook_post_id' => ['required', 'string', 'max:255'],
                'post_text' => ['nullable', 'string', 'max:10000'],
                // 120-image album cap (production alignment).
                'image_urls' => ['required', 'array', 'min:1', 'max:120'],
                'image_urls.*' => ['required', 'string', 'url:http,https', 'max:4096'],
                'published_at' => ['nullable', 'date'],
            ]);

            $retailerSlug = (string) $validated['retailer_slug'];
            $facebookPostId = (string) $validated['facebook_post_id'];
            $postText = (string) ($validated['post_text'] ?? '');
            /** @var list<string> $imageUrls */
            $imageUrls = array_values($validated['image_urls']);
            // Database temporal integrity: timestamps are ALWAYS stored in UTC.
            // Presentation layers (Filament, Blade) convert to Africa/Cairo on render.
            $publishedAtUtc = ! empty($validated['published_at']) ? Carbon::parse((string) $validated['published_at'])->utc() : now()->utc();

            // SSRF guard: image URLs must not target loopback/intranet hosts
            // (metadata endpoints, .local names, private/reserved IP ranges).
            foreach ($imageUrls as $imageUrl) {
                self::rejectPrivateUrl($imageUrl);
            }

            // Zero data loss: persist every incoming payload
            $retailer = Retailer::where('slug', $retailerSlug)->firstOrFail();

            // Kill-switch: disabled retailers get a terminal 202 so scrapers
            // stop retrying WITHOUT burning Gemini credits on Gatekeeper jobs.
            if (! $retailer->is_active || ! $retailer->auto_ingest_enabled) {
                return new JsonResponse(
                    data: [
                        'message' => 'Ingestion disabled for this retailer.',
                        'facebook_post_id' => $facebookPostId,
                        'retailer_slug' => $retailerSlug,
                        'queued' => false,
                    ],
                    status: 202,
                );
            }

            // 24h content fingerprint: shifting scraper fallback hashes must not
            // respawn Gemini classification jobs. SHA-256 over retailer + fully
            // normalized text (newlines collapsed) + sorted query-stripped image
            // paths handles empty-text image-only posts and shared promo intros
            // deterministically — no SQLite LIKE, no false drops.
            $normalizedFullText = trim((string) preg_replace('/\s+/u', ' ', $postText));
            $imagePaths = array_map(
                static fn (string $url): string => ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/'),
                $imageUrls,
            );
            sort($imagePaths, SORT_STRING);

            $fingerprint = hash('sha256', $retailer->id.'|'.$normalizedFullText.'|'.implode('|', $imagePaths));

            // Serialize the check+upsert per (retailer, facebook_post_id): two
            // concurrent webhook retries must not both pass the duplicate check
            // and double-dispatch Gatekeeper (SQLite has no row locks).
            $lock = Cache::lock("ingest_post:{$retailer->id}:{$facebookPostId}", 10);
            if (! $lock->get()) {
                // Contention is transient — nothing was claimed yet, so there
                // is no fingerprint to release.
                return new JsonResponse(
                    data: [
                        'message' => 'Post is already being processed.',
                        'facebook_post_id' => $facebookPostId,
                        'retailer_slug' => $retailerSlug,
                        'queued' => false,
                    ],
                    status: 202,
                );
            }

            try {
                // 1. EXACT (retailer_id, facebook_post_id) check under the lock:
                // a known post is either a terminal duplicate OR an upgrade
                // candidate carrying a richer image set — never blindly re-queued.
                $existingPost = RawFacebookPost::query()
                    ->where('retailer_id', $retailer->id)
                    ->where('facebook_post_id', $facebookPostId)
                    ->first();

                if ($existingPost !== null) {
                    return $this->ingestRicherOrConfirm($existingPost, $imageUrls, $postText, $publishedAtUtc, $fingerprint);
                }

                // 2. 24h content fingerprint: Cache::add is the atomic SET-NX
                // claim (two concurrent duplicates can never both pass); the
                // cached pointer resolves to the previous RawFacebookPost (by
                // ID or facebook_post_id) to feed the richer-set upgrade check.
                if (! Cache::add("ingest_fp:{$fingerprint}", $facebookPostId, now()->addHours(24))) {
                    $fingerprintOwner = $this->resolveFingerprintOwner(
                        (string) Cache::get("ingest_fp:{$fingerprint}", ''),
                        $retailer,
                    );

                    if ($fingerprintOwner !== null) {
                        return $this->ingestRicherOrConfirm($fingerprintOwner, $imageUrls, $postText, $publishedAtUtc, $fingerprint);
                    }
                    // Stale pointer (its row was deleted): fall through as new.
                }

                // 3. Fallback: 24h normalized-text-snippet match — a scraper
                // retry with a SHIFTED facebook_post_id but near-identical text
                // must meet the upgrade check instead of spawning -part-2 flyers.
                $textMatchPost = $this->findRecentTextSnippetMatch($retailer, $postText);

                if ($textMatchPost !== null) {
                    return $this->ingestRicherOrConfirm($textMatchPost, $imageUrls, $postText, $publishedAtUtc, $fingerprint);
                }

                // 4. Genuinely new post: persist, claim the fingerprint pointer,
                // dispatch asynchronously — must return 202 within 100ms.
                $rawPost = RawFacebookPost::query()->create([
                    'retailer_id' => $retailer->id,
                    'facebook_post_id' => $facebookPostId,
                    'post_text' => $postText,
                    'image_urls' => $imageUrls,
                    'published_at' => $publishedAtUtc,
                    'status' => 'pending',
                ]);

                // Upgrade the pointer to the durable raw-post ID.
                Cache::put("ingest_fp:{$fingerprint}", (string) $rawPost->id, now()->addHours(24));

                // afterCommit: never dispatch before the row is durably committed.
                GatekeeperFacebookPostJob::dispatch(
                    retailerSlug: $retailerSlug,
                    facebookPostId: $facebookPostId,
                    postText: $postText,
                    imageUrls: $imageUrls,
                    publishedAt: $publishedAtUtc->toIso8601String(),
                    rawFacebookPostId: $rawPost->id,
                )->afterCommit();

                Log::info('Facebook post ingested, Gatekeeper dispatched.', [
                    'retailer_slug' => $retailerSlug,
                    'facebook_post_id' => $facebookPostId,
                    'image_count' => count($imageUrls),
                ]);

                return new JsonResponse(
                    data: [
                        'message' => 'Accepted for processing.',
                        'facebook_post_id' => $facebookPostId,
                        'retailer_slug' => $retailerSlug,
                        'queued' => true,
                    ],
                    status: 202,
                );
            } finally {
                $lock->release();
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // NEVER echo $e->getMessage() to the client — it leaks DB paths,
            // SQL fragments and host names. Full detail stays in the log.
            if ($fingerprint !== null) {
                Cache::forget("ingest_fp:{$fingerprint}");
            }

            Log::error('RawFacebookIngestController failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(
                data: ['message' => 'Failed to queue post.'],
                status: 500,
            );
        }
    }

    /**
     * Duplicate/upgrade gate for EVERY re-ingest of a known post (exact ID
     * match, fingerprint hit, or 24h text-snippet hit).
     *
     * - `$newUrls === []`: identical/subset re-post → refresh the 24h
     *   fingerprint claim, NEVER shrink the stored set, NEVER clobber terminal
     *   state → 202 `queued: false, duplicate: true`.
     * - `$newUrls !== []`: strictly richer image set → rebuild the single-post
     *   flyer in place, append new pages to a multi-post shared flyer, or
     *   re-dispatch Gatekeeper for a not-yet-processed post.
     *
     * @param  list<string>  $imageUrls
     */
    private function ingestRicherOrConfirm(
        RawFacebookPost $storedPost,
        array $imageUrls,
        string $postText,
        Carbon $publishedAtUtc,
        string $fingerprint,
    ): JsonResponse {
        $storedImages = array_values((array) ($storedPost->image_urls ?? []));
        $newUrls = $this->newSignatureUrls($storedImages, $imageUrls);
        $isRetryable = in_array((string) $storedPost->status, ['pending', 'failed'], true);
        $pointer = (string) ($storedPost->id ?? $storedPost->facebook_post_id);

        if ($newUrls === []) {
            // Identical or subset re-post: refresh the 24h fingerprint claim so
            // rotating scraper re-posts keep deduping. NEVER shrink the stored
            // set, NEVER clobber terminal state — transient Gatekeeper failures
            // are the job's own tries/backoff business; an identical re-ingest
            // is ALWAYS confirmed as a duplicate.
            Cache::put("ingest_fp:{$fingerprint}", $pointer, now()->addHours(24));

            Log::info('[INGEST_DEDUP] Duplicate post content within 24h, skipping.', [
                'duplicate_of' => $storedPost->facebook_post_id,
            ]);

            return new JsonResponse(
                data: [
                    'message' => 'Post already processed (content match).',
                    'facebook_post_id' => $storedPost->facebook_post_id,
                    'queued' => false,
                    'duplicate' => true,
                ],
                status: 202,
            );
        }

        // Strictly richer image set — the merged superset can only grow.
        $mergedImages = array_values(array_unique(array_merge($storedImages, $newUrls)));

        $flyer = $storedPost->flyer_id !== null ? Flyer::find($storedPost->flyer_id) : null;

        if ($flyer === null) {
            if (! $isRetryable) {
                // Terminal post that never produced a flyer (admin-accepted or
                // rejected): the hardened contract forbids resurrecting it —
                // no overwrite, no re-dispatch.
                Cache::put("ingest_fp:{$fingerprint}", $pointer, now()->addHours(24));

                return new JsonResponse(
                    data: [
                        'message' => 'Already processed.',
                        'facebook_post_id' => $storedPost->facebook_post_id,
                        'queued' => false,
                        'duplicate' => true,
                    ],
                    status: 202,
                );
            }

            // Still unprocessed: upgrade the payload and re-dispatch Gatekeeper.
            $storedPost->update([
                'image_urls' => $mergedImages,
                'post_text' => $postText !== '' ? $postText : $storedPost->post_text,
                'published_at' => $publishedAtUtc,
                'status' => 'pending',
            ]);

            Cache::put("ingest_fp:{$fingerprint}", $pointer, now()->addHours(24));

            GatekeeperFacebookPostJob::dispatch(
                retailerSlug: (string) $storedPost->retailer?->slug,
                facebookPostId: (string) $storedPost->facebook_post_id,
                postText: (string) ($storedPost->post_text ?? ''),
                imageUrls: $mergedImages,
                publishedAt: $publishedAtUtc->toIso8601String(),
                rawFacebookPostId: (int) $storedPost->id,
            )->afterCommit();

            Log::info('[INGEST_UPGRADE] Enriched payload on unprocessed post; Gatekeeper re-dispatched.', [
                'raw_post_id' => $storedPost->id,
                'stored_images' => count($storedImages),
                'merged_images' => count($mergedImages),
            ]);

            return new JsonResponse(
                data: [
                    'message' => 'Accepted (enriched payload).',
                    'facebook_post_id' => $storedPost->facebook_post_id,
                    'queued' => true,
                    'upgraded' => true,
                ],
                status: 202,
            );
        }

        Cache::put("ingest_fp:{$fingerprint}", $pointer, now()->addHours(24));

        $flyerRawCount = (int) RawFacebookPost::query()->where('flyer_id', $flyer->id)->count();

        if ($flyerRawCount > 1) {
            // Multi-post shared flyer: append ONLY the genuinely new pages.
            $this->appendPagesToRawFlyer($flyer, $newUrls, (string) $storedPost->facebook_post_id);
            $storedPost->update(['image_urls' => $mergedImages]);

            Log::info('[INGEST_UPGRADE] Appended richer images to shared flyer.', [
                'flyer_id' => $flyer->id,
                'appended' => count($newUrls),
            ]);

            return new JsonResponse(
                data: [
                    'message' => 'Accepted (appended richer images).',
                    'facebook_post_id' => $storedPost->facebook_post_id,
                    'queued' => true,
                    'upgraded' => true,
                ],
                status: 202,
            );
        }

        // Single-post flyer: rebuild IN PLACE — no -part-2, zero R2 orphans.
        $this->rebuildFlyerFromPayload($flyer, $storedPost, $mergedImages);

        Log::info('[INGEST_UPGRADE] Flyer rebuilt in place from richer payload.', [
            'flyer_id' => $flyer->id,
            'pages' => count($mergedImages),
        ]);

        return new JsonResponse(
            data: [
                'message' => 'Accepted (flyer upgraded in place).',
                'facebook_post_id' => $storedPost->facebook_post_id,
                'queued' => true,
                'upgraded' => true,
            ],
            status: 202,
        );
    }

    /**
     * Host-independent photo-signature comparison: Facebook serves the same
     * photo from rotating edge hosts (scontent-cdg/mrs/prg...), so full-URL
     * comparison misses duplicates. Returns the incoming URLs whose signature
     * is NOT already stored (host-variated duplicates collapsed).
     *
     * @param  list<string>  $storedImages
     * @param  list<string>  $incomingImages
     * @return list<string>
     */
    private function newSignatureUrls(array $storedImages, array $incomingImages): array
    {
        $seenSignatures = [];

        foreach ($storedImages as $url) {
            $signature = FacebookMediaHelper::extractPhotoSignature((string) $url);

            if ($signature !== '') {
                $seenSignatures[$signature] = true;
            }
        }

        $newUrls = [];

        foreach ($incomingImages as $url) {
            $signature = FacebookMediaHelper::extractPhotoSignature((string) $url);

            if ($signature !== '' && isset($seenSignatures[$signature])) {
                continue;
            }

            if ($signature !== '') {
                $seenSignatures[$signature] = true;
            }

            $newUrls[] = (string) $url;
        }

        return $newUrls;
    }

    /**
     * Resolve the cached fingerprint pointer to its RawFacebookPost. Historic
     * pointers may hold either the raw-post ID or the facebook_post_id.
     */
    private function resolveFingerprintOwner(string $cachedPointer, Retailer $retailer): ?RawFacebookPost
    {
        $pointer = trim($cachedPointer);

        if ($pointer === '') {
            return null;
        }

        if (ctype_digit($pointer)) {
            $byId = RawFacebookPost::query()->find((int) $pointer);

            if ($byId !== null) {
                return $byId;
            }
        }

        return RawFacebookPost::query()
            ->where('retailer_id', $retailer->id)
            ->where('facebook_post_id', $pointer)
            ->first();
    }

    /**
     * Fallback 24h dedup: a scraper retry with a DIFFERENT facebook_post_id
     * but near-identical text must still meet ingestRicherOrConfirm() instead
     * of respawning a whole new flyer. Whitespace runs in the snippet degrade
     * to '%' so newlines in the stored raw text still match; ESCAPE '\' keeps
     * literal % _ \ inside Arabic copy from acting as wildcards.
     */
    private function findRecentTextSnippetMatch(Retailer $retailer, string $postText): ?RawFacebookPost
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $postText));

        // Image-only / too-short posts must never text-match.
        if (mb_strlen($normalized) < 20) {
            return null;
        }

        $snippet = mb_substr($normalized, 0, 60);
        $likePattern = str_replace(' ', '%', addcslashes($snippet, '\\%_'));

        return RawFacebookPost::query()
            ->where('retailer_id', $retailer->id)
            // Direct >= comparison keeps the created_at index usable.
            ->where('created_at', '>=', now()->subHours(24))
            ->where('post_text', 'LIKE', '%'.$likePattern.'%')
            ->orderBy('id')
            ->first();
    }

    /**
     * Rebuild a single-post flyer IN PLACE from a richer payload: stale
     * pages/items torn down inside ONE transaction, then a fresh full-set
     * processing batch. The slug survives — no -part-2 spam, no 404 churn.
     *
     * ZERO R2 LEAK: every stale page's image_path is captured BEFORE the
     * teardown (afterwards the rows are cascade-deleted and unrecoverable),
     * and the async reap is dispatched ->afterCommit() once the teardown is
     * durably committed — never network I/O inside a transaction.
     *
     * @param  list<string>  $mergedImages
     */
    private function rebuildFlyerFromPayload(Flyer $flyer, RawFacebookPost $storedPost, array $mergedImages): void
    {
        $oldR2Paths = $flyer->pages()
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->filter()
            ->values()
            ->all();

        DB::transaction(function () use ($flyer, $storedPost, $mergedImages): void {
            $flyer->items()->delete();
            $flyer->pages()->delete();
            $flyer->total_pages = count($mergedImages);
            // saveQuietly: the teardown itself must NOT fire the observer purge;
            // finalizeFlyerBatch's audited save() is the single purge authority.
            $flyer->saveQuietly();

            $storedPost->update(['image_urls' => $mergedImages]);
        });

        if ($oldR2Paths !== []) {
            dispatch(static function () use ($oldR2Paths): void {
                try {
                    Storage::disk('r2')->delete($oldR2Paths);
                } catch (Throwable $e) {
                    Log::warning('[INGEST_REBUILD] Stale R2 deletion failed.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterCommit();
        }

        GatekeeperFacebookPostJob::dispatchProcessingBatch(
            flyerId: (int) $flyer->id,
            imageUrls: $mergedImages,
            startPageNumber: 0,
            batchName: 'flyer-'.$flyer->id.'-'.$storedPost->facebook_post_id.'-rebuilt',
            rawPostId: null,
            // Upgrade safety: a partial re-extraction must never kill a live flyer.
            deleteOnZeroItems: false,
        );

        Log::info('[INGEST_REBUILD] Flyer rebuild dispatched.', [
            'flyer_id' => $flyer->id,
            'pages' => count($mergedImages),
            'reaped_r2_objects' => count($oldR2Paths),
        ]);
    }

    /**
     * Append ONLY the genuinely new pages to a multi-post shared flyer.
     *
     * @param  list<string>  $newUrls
     */
    private function appendPagesToRawFlyer(Flyer $flyer, array $newUrls, string $facebookPostId): void
    {
        $maxDbPage = (int) ($flyer->pages()->max('page_number') ?? 0);
        $startPageNumber = max((int) $flyer->total_pages, $maxDbPage);

        DB::transaction(function () use ($flyer, $newUrls): void {
            $flyer->total_pages = (int) $flyer->total_pages + count($newUrls);
            $flyer->saveQuietly();
        });

        GatekeeperFacebookPostJob::dispatchProcessingBatch(
            flyerId: (int) $flyer->id,
            imageUrls: $newUrls,
            startPageNumber: $startPageNumber,
            batchName: 'flyer-'.$flyer->id.'-'.$facebookPostId.'-appended',
            rawPostId: null,
            // Upgrade safety: a partial re-extraction must never kill a live flyer.
            deleteOnZeroItems: false,
        );

        Log::info('[INGEST_APPEND] Page append dispatched.', [
            'flyer_id' => $flyer->id,
            'appended' => count($newUrls),
            'start_page' => $startPageNumber + 1,
        ]);
    }

    /**
     * Reject URLs pointing at loopback / link-local / private / reserved
     * targets so a malicious scraper payload cannot pivot into the VPC.
     *
     * @throws ValidationException
     */
    private static function rejectPrivateUrl(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            throw ValidationException::withMessages(['image_urls' => 'Invalid image URL.']);
        }

        // Strip IPv6 brackets.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isIpLiteral) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($isPublic === false) {
                throw ValidationException::withMessages(['image_urls' => 'Private or reserved IP targets are not allowed.']);
            }

            return;
        }

        // Hostnames: block loopback and intranet-only naming.
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localdomain')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')
            || ! str_contains($host, '.')
        ) {
            throw ValidationException::withMessages(['image_urls' => 'Private or reserved hosts are not allowed.']);
        }
    }
}
