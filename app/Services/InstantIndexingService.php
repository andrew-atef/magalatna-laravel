<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InstantIndexingService
{
    private const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const GOOGLE_INDEXING_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    private const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/indexing';

    public function notifyIndexNow(string $url): bool
    {
        try {
            $url = trim($url);
            if ($url === '') {
                return false;
            }

            $key = (string) config('services.indexnow.key');
            if (trim($key) === '') {
                $key = (string) config('services.indexnow_key', '');
            }

            if (trim($key) === '') {
                Log::warning('InstantIndexingService: INDEXNOW_KEY missing, skipping IndexNow.', ['url' => $url]);

                return false;
            }

            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '') {
                $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
            }

            if (trim($host) === '') {
                Log::warning('InstantIndexingService: Unable to determine host for IndexNow.', ['url' => $url]);

                return false;
            }

            $payload = [
                'host' => $host,
                'key' => $key,
                'keyLocation' => rtrim((string) config('app.url'), '/') . '/' . $key . '.txt',
                'urlList' => [$url],
            ];

            // Use base_uri from config if provided, else default
            $endpoint = (string) (config('services.indexnow.base_uri') ?? self::INDEXNOW_ENDPOINT);
            if (trim($endpoint) === '') {
                $endpoint = self::INDEXNOW_ENDPOINT;
            }

            $response = Http::timeout(10)->post($endpoint, $payload);

            if ($response->successful()) {
                Log::info('InstantIndexingService: IndexNow notified.', ['url' => $url, 'host' => $host]);

                return true;
            }

            Log::warning('InstantIndexingService: IndexNow failed.', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('InstantIndexingService: IndexNow exception.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function notifyGoogle(string $url): bool
    {
        try {
            $url = trim($url);
            if ($url === '') {
                return false;
            }

            $enabled = (bool) config('services.google_indexing.enabled', false);
            if (! $enabled) {
                Log::debug('InstantIndexingService: Google indexing disabled, skipping.', ['url' => $url]);

                return false;
            }

            $credentialsPath = (string) config('services.google_indexing.credentials_path');
            if (trim($credentialsPath) === '') {
                $credentialsPath = (string) storage_path((string) env('GOOGLE_INDEXING_CREDENTIALS_FILE', 'app/google-service-account.json'));
            }

            if (! is_file($credentialsPath)) {
                Log::warning('InstantIndexingService: Google credentials file not found.', [
                    'path' => $credentialsPath,
                    'url' => $url,
                ]);

                return false;
            }

            $json = (string) file_get_contents($credentialsPath);
            $credentials = json_decode($json, true);

            if (! is_array($credentials) || empty($credentials['private_key']) || empty($credentials['client_email'])) {
                Log::warning('InstantIndexingService: Invalid Google credentials JSON.', ['path' => $credentialsPath]);

                return false;
            }

            $jwt = $this->buildGoogleJwt($credentials);
            if ($jwt === null) {
                return false;
            }

            $tokenResponse = Http::asForm()->timeout(10)->post(self::GOOGLE_TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $tokenResponse->successful()) {
                Log::warning('InstantIndexingService: Google OAuth token exchange failed.', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);

                return false;
            }

            $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
            if (trim($accessToken) === '') {
                Log::warning('InstantIndexingService: Google access_token empty.', ['response' => $tokenResponse->json()]);

                return false;
            }

            $indexResponse = Http::withToken($accessToken)
                ->timeout(10)
                ->acceptJson()
                ->post(self::GOOGLE_INDEXING_URL, [
                    'url' => $url,
                    'type' => 'URL_UPDATED',
                ]);

            if ($indexResponse->successful()) {
                Log::info('InstantIndexingService: Google Indexing notified.', ['url' => $url]);

                return true;
            }

            Log::warning('InstantIndexingService: Google Indexing API failed.', [
                'url' => $url,
                'status' => $indexResponse->status(),
                'body' => $indexResponse->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('InstantIndexingService: Google notify exception.', [
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
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT',
            ];

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

            $privateKey = (string) $credentials['private_key'];
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);

            if (! $ok) {
                Log::error('InstantIndexingService: openssl_sign failed for Google JWT.');

                return null;
            }

            $base64Signature = $this->base64UrlEncode($signature);

            return $unsigned . '.' . $base64Signature;
        } catch (Throwable $e) {
            Log::error('InstantIndexingService: buildGoogleJwt exception.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
