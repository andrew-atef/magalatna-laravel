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
        try {
            $validated = $request->validate([
                'retailer_slug' => ['required', 'string', 'max:255', 'exists:retailers,slug'],
                'facebook_post_id' => ['required', 'string', 'max:255'],
                'post_text' => ['required', 'string', 'max:10000'],
                'image_urls' => ['required', 'array', 'min:1', 'max:20'],
                'image_urls.*' => ['required', 'string', 'url', 'max:2048'],
                'published_at' => ['required', 'date'],
            ]);

            $retailerSlug = (string) $validated['retailer_slug'];
            $facebookPostId = (string) $validated['facebook_post_id'];
            $postText = (string) $validated['post_text'];
            /** @var list<string> $imageUrls */
            $imageUrls = array_values($validated['image_urls']);
            // Database temporal integrity: timestamps are ALWAYS stored in UTC.
            // Presentation layers (Filament, Blade) convert to Africa/Cairo on render.
            $publishedAtUtc = Carbon::parse((string) $validated['published_at'])->utc();

            // Zero data loss: persist every incoming payload
            $retailer = Retailer::where('slug', $retailerSlug)->firstOrFail();

            // 24h content fingerprint: shifting scraper fallback hashes must not
            // respawn Gemini classification jobs for identical post text.
            $normalizedSnippet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $postText)), 0, 100);
            if ($normalizedSnippet !== '') {
                $likeSnippet = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedSnippet);
                $recentDuplicate = RawFacebookPost::where('retailer_id', $retailer->id)
                    ->where('created_at', '>=', now()->subHours(24))
                    ->where('post_text', 'like', $likeSnippet . '%')
                    ->first();

                if ($recentDuplicate !== null) {
                    Log::info("[INGEST_DEDUP] Duplicate post text received within 24h for {$retailerSlug}, skipping duplicate job dispatch.");

                    return new JsonResponse(
                        data: [
                            'message' => 'Post already processed (content match).',
                            'facebook_post_id' => $recentDuplicate->facebook_post_id,
                            'duplicate' => true,
                        ],
                        status: 202,
                    );
                }
            }

            // Idempotency: never clobber an administrative/terminal state.
            // accepted / manually_approved / rejected posts keep their status;
            // only genuinely new or retryable (pending/failed) posts re-enter the pipeline.
            $existingPost = RawFacebookPost::where('retailer_id', $retailer->id)
                ->where('facebook_post_id', $facebookPostId)
                ->first();

            if ($existingPost !== null && ! in_array($existingPost->status, ['pending', 'failed'], true)) {
                $existingPost->update([
                    'post_text' => $postText,
                    'image_urls' => $imageUrls,
                    'published_at' => $publishedAtUtc,
                ]);

                Log::info('Facebook post re-ingested with terminal status preserved, Gatekeeper NOT redispatched.', [
                    'retailer_slug' => $retailerSlug,
                    'facebook_post_id' => $facebookPostId,
                    'status' => $existingPost->status,
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

            $rawPost = RawFacebookPost::updateOrCreate(
                [
                    'retailer_id' => $retailer->id,
                    'facebook_post_id' => $facebookPostId,
                ],
                [
                    'post_text' => $postText,
                    'image_urls' => $imageUrls,
                    'published_at' => $publishedAtUtc,
                    'status' => 'pending',
                ]
            );

            // Dispatch asynchronously — must return 202 within 100ms, no blocking work here.
            GatekeeperFacebookPostJob::dispatch(
                retailerSlug: $retailerSlug,
                facebookPostId: $facebookPostId,
                postText: $postText,
                imageUrls: $imageUrls,
                publishedAt: $publishedAtUtc->toIso8601String(),
                rawFacebookPostId: $rawPost->id,
            );

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
            Log::error('RawFacebookIngestController failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(
                data: ['message' => 'Failed to queue post.', 'error' => $e->getMessage()],
                status: 500,
            );
        }
    }
}
