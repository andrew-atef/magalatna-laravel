<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class RawFacebookIngestController extends Controller
{
    /**
     * Handle raw Facebook post ingestion.
     *
     * Validates payload, dispatches Gatekeeper job, returns 202 within 100ms.
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
                'image_urls' => ['required', 'array', 'min:1', 'max:100'],
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

            // Atomic claim: Cache::add is a single SET-if-absent (NX) — two
            // concurrent duplicates can never both pass. Cache::get runs ONLY
            // on collision, to report which post already won.
            if (! Cache::add("ingest_fp:{$fingerprint}", $facebookPostId, now()->addHours(24))) {
                $existingFingerprintPostId = (string) Cache::get("ingest_fp:{$fingerprint}", $facebookPostId);

                Log::info('[INGEST_DEDUP] Duplicate post content within 24h, skipping.', [
                    'retailer_slug' => $retailerSlug,
                    'duplicate_of' => $existingFingerprintPostId,
                ]);

                return new JsonResponse(
                    data: [
                        'message' => 'Post already processed (content match).',
                        'facebook_post_id' => $existingFingerprintPostId,
                        'duplicate' => true,
                    ],
                    status: 202,
                );
            }

            // Serialize the check+upsert per (retailer, facebook_post_id): two
            // concurrent webhook retries must not both pass the terminal-status
            // check and double-dispatch Gatekeeper (SQLite has no row locks).
            $lock = Cache::lock("ingest_post:{$retailer->id}:{$facebookPostId}", 10);
            if (! $lock->get()) {
                // Contention is transient — release the fingerprint claim so a
                // request that never ran cannot poison dedup for 24h.
                Cache::forget("ingest_fp:{$fingerprint}");

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
                // Idempotency: never clobber an administrative/terminal state —
                // accepted/manually_approved/rejected posts keep BOTH their
                // status AND their stored content; only genuinely new or
                // retryable (pending/failed) posts re-enter the pipeline.
                [$rawPost, $shouldQueue] = DB::transaction(function () use ($retailer, $facebookPostId, $postText, $imageUrls, $publishedAtUtc): array {
                    $existingPost = RawFacebookPost::query()
                        ->where('retailer_id', $retailer->id)
                        ->where('facebook_post_id', $facebookPostId)
                        ->first();

                    if ($existingPost !== null && ! in_array($existingPost->status, ['pending', 'failed'], true)) {
                        return [$existingPost, false];
                    }

                    $post = RawFacebookPost::query()->updateOrCreate(
                        [
                            'retailer_id' => $retailer->id,
                            'facebook_post_id' => $facebookPostId,
                        ],
                        [
                            'post_text' => $postText,
                            'image_urls' => $imageUrls,
                            'published_at' => $publishedAtUtc,
                            'status' => 'pending',
                        ],
                    );

                    return [$post, true];
                });

                if (! $shouldQueue) {
                    Log::info('Facebook post re-ingested with terminal status preserved, Gatekeeper NOT redispatched.', [
                        'retailer_slug' => $retailerSlug,
                        'facebook_post_id' => $facebookPostId,
                        'status' => $rawPost->status,
                    ]);

                    return new JsonResponse(
                        data: [
                            'message' => 'Already processed.',
                            'facebook_post_id' => $facebookPostId,
                            'retailer_slug' => $retailerSlug,
                            'queued' => false,
                        ],
                        status: 202,
                    );
                }
            } catch (\Throwable $e) {
                // DB failure: release the fingerprint claim so the scraper's
                // valid retry is not locked out for 24h by a transient error.
                Cache::forget("ingest_fp:{$fingerprint}");

                throw $e;
            } finally {
                $lock->release();
            }

            // Dispatch asynchronously — must return 202 within 100ms, no blocking
            // work here. afterCommit: never before the DB transaction above is
            // durably committed.
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
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
