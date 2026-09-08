<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GatekeeperFacebookPostJob;
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
            $publishedAt = (string) $validated['published_at'];

            // Dispatch asynchronously — must return 202 within 100ms, no blocking work here.
            GatekeeperFacebookPostJob::dispatch(
                retailerSlug: $retailerSlug,
                facebookPostId: $facebookPostId,
                postText: $postText,
                imageUrls: $imageUrls,
                publishedAt: $publishedAt,
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
