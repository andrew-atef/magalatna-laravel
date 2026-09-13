<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InstantIndexingService
{
    private const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_INDEXING_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    private const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/indexing';

    public function notifyGoogle(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $enabled = (bool) config('services.google_indexing.enabled', false);
        if (! $enabled) {
            return false;
        }

        $credentialsPath = (string) config('services.google_indexing.credentials_path');
        if ($credentialsPath === '' || ! is_file($credentialsPath)) {
            Log::warning('InstantIndexingService: Google service credentials not found.', ['path' => $credentialsPath]);

            return false;
        }

        try {
            // CACHE GOOGLE ACCESS TOKEN: Re-use token for 55 minutes to avoid OAuth 429 quota exhaustion
            $accessToken = Cache::remember('google_indexing_access_token', 3300, function () use ($credentialsPath): ?string {
                $credentials = json_decode((string) file_get_contents($credentialsPath), true);
                if (! is_array($credentials) || empty($credentials['private_key']) || empty($credentials['client_email'])) {
                    return null;
                }

                $jwt = $this->buildGoogleJwt($credentials);
                if ($jwt === null) {
                    return null;
                }

                $response = Http::asForm()->timeout(10)->post(self::GOOGLE_TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                return $response->successful() ? (string) $response->json('access_token') : null;
            });

            if ($accessToken === null || $accessToken === '') {
                Log::warning('InstantIndexingService: Failed to retrieve Google access token.');

                return false;
            }

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->acceptJson()
                ->post(self::GOOGLE_INDEXING_URL, [
                    'url' => $url,
                    'type' => 'URL_UPDATED',
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('InstantIndexingService: Google indexing request failed.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function buildGoogleJwt(array $credentials): ?string
    {
        try {
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $now = time();
            $payload = [
                'iss' => $credentials['client_email'],
                'scope' => self::GOOGLE_SCOPE,
                'aud' => self::GOOGLE_TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $base64Header = $this->base64UrlEncode((string) json_encode($header));
            $base64Payload = $this->base64UrlEncode((string) json_encode($payload));
            $unsigned = $base64Header . '.' . $base64Payload;

            // Normalize private key to handle both single-line escaped strings and raw certificates
            $privateKey = str_replace('\n', "\n", (string) $credentials['private_key']);
            $signature = '';

            if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            return $unsigned . '.' . $this->base64UrlEncode($signature);
        } catch (Throwable) {
            return null;
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
