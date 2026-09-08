<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CloudflareCacheService
{
    private const ENDPOINT_TEMPLATE = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';

    private const CHUNK_SIZE = 250;

    public function purgeUrl(string $url): bool
    {
        return $this->purgeUrls([$url]);
    }

    /**
     * Expand every URL with its Markdown variant ?_fmt=md
     *
     * @param list<string> $urls
     * @return list<string>
     */
    private function withMarkdownVariants(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $out[] = $url;
            if (str_contains($url, '_fmt=md')) {
                continue;
            }
            if (str_contains($url, '#')) {
                [$base, $frag] = explode('#', $url, 2);
                $sep = str_contains($base, '?') ? '&' : '?';
                $out[] = $base . $sep . '_fmt=md#' . $frag;
            } elseif (str_contains($url, '?')) {
                $out[] = $url . '&_fmt=md';
            } else {
                $out[] = $url . '?_fmt=md';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $urls
     */
    public function purgeUrls(array $urls): bool
    {
        try {
            $urls = $this->normalizeAndDeduplicate($urls);
            $urls = $this->withMarkdownVariants($urls);

            if ($urls === []) {
                Log::info('CloudflareCacheService: No URLs to purge after normalization.');

                return true;
            }

            $apiToken = (string) config('services.cloudflare.api_token');
            $zoneId = (string) config('services.cloudflare.zone_id');

            if (trim($apiToken) === '' || trim($zoneId) === '') {
                Log::warning('CloudflareCacheService: Missing CLOUDFLARE_CACHE_API_TOKEN or CLOUDFLARE_ZONE_ID, skipping purge.', [
                    'urls_count' => count($urls),
                ]);

                return false;
            }

            $endpoint = sprintf(self::ENDPOINT_TEMPLATE, $zoneId);
            $chunks = array_chunk($urls, self::CHUNK_SIZE);

            $overallSuccess = true;

            foreach ($chunks as $index => $chunk) {
                try {
                    $response = Http::withToken($apiToken)
                        ->timeout(10)
                        ->acceptJson()
                        ->post($endpoint, [
                            'files' => $chunk,
                        ]);

                    if ($response->successful()) {
                        $body = $response->json();
                        $success = (bool) ($body['success'] ?? false);
                        if ($success) {
                            Log::info('CloudflareCacheService: Purged chunk successfully.', [
                                'chunk' => $index + 1,
                                'count' => count($chunk),
                            ]);
                        } else {
                            Log::warning('CloudflareCacheService: Cloudflare API returned failure for chunk.', [
                                'chunk' => $index + 1,
                                'chunk_urls' => $chunk,
                                'response' => $body,
                            ]);
                            $overallSuccess = false;
                        }
                    } else {
                        Log::warning('CloudflareCacheService: Purge failed with HTTP status.', [
                            'chunk' => $index + 1,
                            'status' => $response->status(),
                            'body' => $response->body(),
                            'chunk_urls' => $chunk,
                        ]);
                        $overallSuccess = false;
                    }
                } catch (Throwable $e) {
                    Log::error('CloudflareCacheService: Exception while purging chunk.', [
                        'chunk' => $index + 1,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $overallSuccess = false;
                }
            }

            return $overallSuccess;
        } catch (Throwable $e) {
            Log::error('CloudflareCacheService: Unexpected exception in purgeUrls.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function normalizeAndDeduplicate(array $urls): array
    {
        $normalized = [];

        foreach ($urls as $url) {
            $u = trim((string) $url);
            if ($u === '') {
                continue;
            }

            // Ensure absolute URL with scheme
            if (! str_starts_with($u, 'http://') && ! str_starts_with($u, 'https://')) {
                // Try to prepend APP_URL if relative
                $u = rtrim((string) config('app.url', ''), '/') . '/' . ltrim($u, '/');
            }

            // Remove fragment for cache purge? Keep as is - Cloudflare purges exact file URL including query but not fragment
            // Normalize by trimming trailing slash inconsistencies? Keep exact URL
            // For homepage both variants are intentional, so keep distinct
            $normalized[] = $u;
        }

        // Deduplicate
        $unique = array_values(array_unique($normalized));

        // Filter empty after dedup
        return array_values(array_filter($unique, static fn (string $u): bool => $u !== ''));
    }
}
