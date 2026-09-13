<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CloudflareCacheService
{
    private const ENDPOINT_TEMPLATE = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
    private const CHUNK_SIZE = 30; // Strictly capped to 30 URLs per batch for edge reliability

    /**
     * @param list<string> $urls
     */
    public function purgeUrls(array $urls): bool
    {
        $cleanUrls = $this->normalizeAndDeduplicate($urls);
        if ($cleanUrls === []) {
            return true;
        }

        $apiToken = (string) config('services.cloudflare.api_token');
        $zoneId = (string) config('services.cloudflare.zone_id');

        if ($apiToken === '' || $zoneId === '') {
            Log::warning('CloudflareCacheService: API token or Zone ID missing.');

            return false;
        }

        $endpoint = sprintf(self::ENDPOINT_TEMPLATE, $zoneId);
        $chunks = array_chunk($cleanUrls, self::CHUNK_SIZE);
        $success = true;

        foreach ($chunks as $chunk) {
            try {
                $response = Http::withToken($apiToken)
                    ->timeout(10)
                    ->acceptJson()
                    ->post($endpoint, ['files' => $chunk]);

                if (! $response->successful() || ! ($response->json('success') ?? false)) {
                    Log::warning('CloudflareCacheService: Edge purge chunk rejected.', [
                        'status' => $response->status(),
                        'response' => $response->json(),
                    ]);
                    $success = false;
                }
            } catch (Throwable $e) {
                Log::error('CloudflareCacheService: Exception during purge.', ['error' => $e->getMessage()]);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Normalize, strip URL fragments, and append markdown representations.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    private function normalizeAndDeduplicate(array $urls): array
    {
        $result = [];

        foreach ($urls as $url) {
            $u = trim((string) $url);
            if ($u === '') {
                continue;
            }

            // STRIP FRAGMENT: Cloudflare rejects URLs containing hash anchors
            if (str_contains($u, '#')) {
                $u = explode('#', $u, 2)[0];
            }

            $result[] = $u;

            // Generate Markdown variant for CDN cache invalidation
            $separator = str_contains($u, '?') ? '&' : '?';
            $result[] = $u . $separator . '_fmt=md';
        }

        return array_values(array_unique(array_filter($result)));
    }
}
