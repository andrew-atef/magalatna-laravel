<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GeminiRateLimitedException;
use App\Models\Flyer;
use App\Services\ProductDataNormalizer;
use App\Support\PublicUrlGuard;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class GeminiVisionService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SECONDS = 60;

    /** Hard cap per classification image download — protects the 128MB worker. */
    private const MAX_INLINE_IMAGE_BYTES = 15 * 1024 * 1024;

    /** Short cooldown for transient failures (5xx / timeout / malformed body). */
    private const TRANSIENT_COOLDOWN_SECONDS = 60;

    /** Long cooldown for models Google reports as unsupported (400/404). */
    private const UNSUPPORTED_COOLDOWN_SECONDS = 21600;

    /**
     * Ordered model waterfall from config('services.gemini.models').
     * Config-only by contract: env() is resolved once in config/services.php.
     *
     * @return list<array{name: string, rpm: int, rpd: int}>
     */
    private function configuredModels(): array
    {
        $models = config('services.gemini.models', []);

        if (! is_array($models)) {
            return [];
        }

        $normalized = [];

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = trim((string) ($model['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'rpm' => max(0, (int) ($model['rpm'] ?? 5)),
                'rpd' => max(0, (int) ($model['rpd'] ?? 20)),
            ];
        }

        return $normalized;
    }

    /**
     * Phase 1: Gatekeeper / Classification.
     *
     * Classify whether a post is an actual retail flyer/catalog vs contest, recipe, branch announcement, or meme.
     *
     * @return array{is_flyer: bool, reason: string, flyer_title: ?string, valid_from: ?string, valid_until: ?string, applicable_governorates: string[]}
     *
     * @throws RuntimeException
     */
    /**
     * @param  list<string>  $sampleImageUrls  Up to 3 sampled image URLs (cover, middle, last)
     */
    public function classifyPost(string $postText, array $sampleImageUrls = [], int $totalImages = 1): array
    {
        try {
            $apiKey = $this->apiKey();
            $postText = trim($postText);
            $sampleImageUrls = array_values(array_unique(array_filter(array_map(static fn (mixed $u): string => trim((string) $u), $sampleImageUrls), static fn (string $u): bool => $u !== '')));
            // Limit to 3 for payload efficiency
            $sampleImageUrls = array_slice($sampleImageUrls, 0, 3);

            if ($postText === '' && $sampleImageUrls === []) {
                return [
                    'is_flyer' => false,
                    'reason' => 'Empty post text and no image provided.',
                    'flyer_title' => null,
                    'valid_from' => null,
                    'valid_until' => null,
                    'applicable_governorates' => [],
                ];
            }

            // Backward compat: if caller passed single string as array element with old signature, handle
            // (Gatekeeper now passes array, but keep support for legacy single string)
            if (count($sampleImageUrls) === 1 && is_string($sampleImageUrls[0]) && str_starts_with($sampleImageUrls[0], 'http') === false && isset($sampleImageUrls[0][0]) === false) {
                // No-op
            }

            $parts = [];

            $systemInstruction = $this->classificationPrompt($totalImages);

            // Post text part — inject TOTAL_IMAGES_IN_POST for Flash 1-Day detection
            $parts[] = [
                'text' => $systemInstruction."\n\nTOTAL_IMAGES_IN_POST: {$totalImages}\nPOST_TEXT:\n".$postText,
            ];

            // Fetch all sampled images (max 3) for multimodal inspection — cover trap mitigation
            foreach ($sampleImageUrls as $idx => $sampleUrl) {
                $sampleUrl = trim((string) $sampleUrl);
                if ($sampleUrl === '') {
                    continue;
                }
                $imagePart = $this->fetchImageAsInlineData($sampleUrl);
                if ($imagePart !== null) {
                    $parts[] = $imagePart;
                } else {
                    Log::warning('Failed to fetch sample image for classification, proceeding with text.', [
                        'url' => $sampleUrl,
                        'index' => $idx,
                    ]);
                    $parts[] = ['text' => 'Sample image '.($idx + 1).' URL (failed to download): '.$sampleUrl];
                }
            }

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'topP' => 0.95,
                    'topK' => 32,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->classificationSchema(),
                ],
            ];

            $decoded = $this->callGemini($payload, $apiKey);

            return $this->normalizeClassificationResult($decoded);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('classifyPost failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('Failed to classify post: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Phase 2: High-Density Page OCR.
     *
     * Extract all product offers from a flyer page image.
     *
     * @param  string  $imageBinary  Raw binary of WebP/JPEG/PNG page image
     * @return array<int, array{product_name: string, sale_price: float, old_price: ?float, unit: ?string, category: string, brand_name: ?string, bundle_condition: ?string, extra_attributes: array<string, string>}>
     *
     * @throws RuntimeException
     */
    public function extractProductsFromPage(string $imageBinary): array
    {
        try {
            $apiKey = $this->apiKey();

            if ($imageBinary === '') {
                throw new RuntimeException('Image binary is empty for OCR extraction.');
            }

            $mime = $this->detectMimeType($imageBinary);
            $base64 = base64_encode($imageBinary);

            $prompt = $this->ocrPrompt();

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => $mime,
                                    'data' => $base64,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'topP' => 0.95,
                    'topK' => 40,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->ocrSchema(),
                ],
            ];

            $decoded = $this->callGemini($payload, $apiKey);

            // Expected: { "items": [ ... ] } or { "products": [...] } fallback
            $items = $decoded['items'] ?? $decoded['products'] ?? $decoded['data'] ?? null;

            if ($items === null) {
                // If root is already array (strict schema may return array directly), handle
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    $items = $decoded;
                } else {
                    Log::warning('OCR response missing items key, returning empty.', [
                        'decoded_keys' => array_keys($decoded),
                    ]);

                    return [];
                }
            }

            if (! is_array($items)) {
                Log::error('OCR items is not an array.', [
                    'items_type' => gettype($items),
                ]);

                throw new RuntimeException('Invalid OCR response: items is not an array.');
            }

            return $this->normalizeOcrItems($items);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('extractProductsFromPage failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('Failed to extract products from page: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Structured-JSON Gemini call through the ordered model waterfall.
     *
     * Auth travels in the `x-goog-api-key` header — never `?key=` in the
     * query string, which leaks into access/proxy logs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     * @throws GeminiRateLimitedException
     */
    private function callGemini(array $payload, string $apiKey): array
    {
        $result = $this->executeWithModelFallback(
            fn (string $modelName): Response => $this->postToModel($modelName, $payload, $apiKey),
            true,
        );

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Ordered multi-model waterfall + per-model circuit breaker.
     *
     * Iterates config('services.gemini.models') in STRICT priority order and
     * returns the FIRST success. Every skip decision is local (cache-backed,
     * SQLite-safe) so a throttled model costs <1ms — never a multi-second
     * usleep() inside the timeout-bounded worker when the next model is ready
     * right behind it.
     *
     * $requestBuilder receives the model name and performs exactly ONE HTTP
     * request (it may throw ConnectionException on network failure).
     *
     * @param  callable(string): Response  $requestBuilder
     * @return array<string, mixed>|string decoded JSON (jsonMode) or candidate text
     *
     * @throws RuntimeException bad API key, or all models permanently failed / daily-exhausted
     * @throws GeminiRateLimitedException all models temporarily throttled — the job should release()
     */
    private function executeWithModelFallback(callable $requestBuilder, bool $jsonMode = true): array|string
    {
        $models = $this->configuredModels();

        if ($models === []) {
            throw new RuntimeException('No Gemini models configured (services.gemini.models).');
        }

        // Gemini free-tier RPD resets at midnight Pacific, NOT Cairo/UTC.
        $ptNow = now('America/Los_Angeles');
        $ptDate = $ptNow->toDateString();
        $ptEndOfDay = $ptNow->copy()->endOfDay();

        $earliestRetryAfter = null;
        $anyTemporaryFailure = false;
        $attemptOrder = 0;

        foreach ($models as $model) {
            $attemptOrder++;
            $name = $model['name'];
            $rpm = $model['rpm'];
            $rpd = $model['rpd'];
            $cooldownKey = "gemini_cooldown:{$name}";
            $rpdKey = "gemini_rpd:{$name}:{$ptDate}";
            $rpmKey = "gemini_rpm:{$name}";

            // 1) Local circuit breaker: a cooling model is skipped in <1ms.
            if (Cache::has($cooldownKey)) {
                $reason = (string) Cache::get($cooldownKey, '');

                // 'unsupported' (400/404, 6h) is NOT a 15-60s retry candidate.
                if ($reason !== 'unsupported') {
                    $anyTemporaryFailure = true;
                    $until = (int) Cache::get("gemini_cooldown_until:{$name}", 0);
                    $wait = $until > time() ? $until - time() : self::TRANSIENT_COOLDOWN_SECONDS;
                    $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, max(5, $wait));
                }

                $this->safeLog('debug', 'Gemini model on cooldown, skipping.', [
                    'model' => $name,
                    'reason' => $reason,
                    'attempt_order' => $attemptOrder,
                ]);

                continue;
            }

            // 2) Local RPD quota (Pacific-Time day).
            if ($rpd > 0 && (int) Cache::get($rpdKey, 0) >= $rpd) {
                $this->safeLog('debug', 'Gemini model daily quota exhausted, skipping.', [
                    'model' => $name,
                    'rpd' => $rpd,
                    'attempt_order' => $attemptOrder,
                ]);

                continue;
            }

            // 3) Local RPM quota — skip immediately, never sleep in-worker.
            if ($rpm > 0 && RateLimiter::tooManyAttempts($rpmKey, $rpm)) {
                $wait = max(5, RateLimiter::availableIn($rpmKey));
                $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, $wait);
                $anyTemporaryFailure = true;

                $this->safeLog('debug', 'Gemini model RPM window full, skipping.', [
                    'model' => $name,
                    'rpm' => $rpm,
                    'retry_after' => $wait,
                    'attempt_order' => $attemptOrder,
                ]);

                continue;
            }

            // 4) Budget the attempt: RPM hit + atomic RPD increment. Cache::add
            //    seeds the daily counter WITH its PT-midnight TTL only when the
            //    key is newly created; increment then bumps it atomically.
            RateLimiter::hit($rpmKey, 60);
            Cache::add($rpdKey, 0, $ptEndOfDay);
            Cache::increment($rpdKey);

            try {
                $response = $requestBuilder($name);
            } catch (Throwable $e) {
                // ConnectionException / timeout — short cooldown, fail over.
                $this->putCooldown($name, 'connection_error', self::TRANSIENT_COOLDOWN_SECONDS);
                $anyTemporaryFailure = true;
                $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, self::TRANSIENT_COOLDOWN_SECONDS);

                $this->safeLog('warning', 'Gemini model connection failure, failing over.', [
                    'model' => $name,
                    'attempt_order' => $attemptOrder,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $status = $response->status();

            // 401/403 = bad API key, NOT model-specific: fail fast — never burn
            // the whole waterfall on an auth error.
            if ($status === 401 || $status === 403) {
                throw new RuntimeException('Gemini API authentication failed (HTTP '.$status.') — check GEMINI_API_KEY.');
            }

            // 400/404 = model not found / unsupported: 6-hour cooldown.
            if ($status === 400 || $status === 404) {
                $this->putCooldown($name, 'unsupported', self::UNSUPPORTED_COOLDOWN_SECONDS);

                $this->safeLog('warning', 'Gemini model unsupported, cooling down 6h.', [
                    'model' => $name,
                    'status' => $status,
                    'attempt_order' => $attemptOrder,
                    'body_snippet' => substr((string) $response->body(), 0, 500),
                ]);

                continue;
            }

            if ($status === 429) {
                $retryAfter = (int) $response->header('Retry-After');
                $body = (string) $response->body();
                $isDailyQuota = $retryAfter > 120
                    || preg_match('/perday|requestsperday|generaterequestsperday/i', $body) === 1;

                if ($isDailyQuota && $rpd > 0) {
                    // Google says the DAY is done — mirror it locally so every
                    // later call skips this model until the PT reset.
                    Cache::put($rpdKey, $rpd, $ptEndOfDay);

                    $this->safeLog('warning', 'Gemini model daily quota exhausted (429), skipping for the PT day.', [
                        'model' => $name,
                        'retry_after' => $retryAfter,
                        'attempt_order' => $attemptOrder,
                    ]);

                    continue;
                }

                // RPM burst: short cooldown, then straight to the next model.
                $cooldown = max(60, min(120, $retryAfter > 0 ? $retryAfter : 65));
                $this->putCooldown($name, '429_rpm', $cooldown);
                $anyTemporaryFailure = true;
                $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, $cooldown);

                $this->safeLog('warning', 'Gemini model rate limited (429 RPM), failing over.', [
                    'model' => $name,
                    'cooldown' => $cooldown,
                    'attempt_order' => $attemptOrder,
                ]);

                continue;
            }

            if (! $response->successful()) {
                // 5xx (and any other non-success): short cooldown + failover.
                $this->putCooldown($name, 'http_'.$status, self::TRANSIENT_COOLDOWN_SECONDS);
                $anyTemporaryFailure = true;
                $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, self::TRANSIENT_COOLDOWN_SECONDS);

                $this->safeLog('warning', 'Gemini model HTTP error, failing over.', [
                    'model' => $name,
                    'status' => $status,
                    'attempt_order' => $attemptOrder,
                    'body_snippet' => substr((string) $response->body(), 0, 500),
                ]);

                continue;
            }

            // 200 — validate the payload BEFORE declaring success: a malformed
            // body / empty candidate is a MODEL failure, not a caller failure.
            try {
                if ($jsonMode) {
                    $body = (string) $response->body();

                    if (trim($body) === '') {
                        throw new RuntimeException('Gemini API returned empty body.');
                    }

                    $raw = json_decode($body, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new RuntimeException('Gemini API returned invalid JSON: '.json_last_error_msg());
                    }

                    if (! is_array($raw)) {
                        throw new RuntimeException('Gemini API JSON is not an object.');
                    }

                    $result = $this->extractJsonFromCandidate($raw);
                } else {
                    $json = $response->json();
                    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    if (! is_string($text) || trim($text) === '') {
                        throw new RuntimeException('Gemini API returned empty candidate text.');
                    }

                    $result = trim($text);
                }
            } catch (Throwable $e) {
                $this->putCooldown($name, 'invalid_response', self::TRANSIENT_COOLDOWN_SECONDS);
                $anyTemporaryFailure = true;
                $earliestRetryAfter = min($earliestRetryAfter ?? PHP_INT_MAX, self::TRANSIENT_COOLDOWN_SECONDS);

                $this->safeLog('warning', 'Gemini model returned malformed response, failing over.', [
                    'model' => $name,
                    'attempt_order' => $attemptOrder,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->safeLog('info', 'Gemini model succeeded.', [
                'model' => $name,
                'attempt_order' => $attemptOrder,
                'json_mode' => $jsonMode,
            ]);

            return $result;
        }

        // Every model skipped/failed. Temporary throttling → the queue job
        // releases itself and retries after the earliest window; permanent
        // exhaustion → hard failure.
        if ($anyTemporaryFailure) {
            $retry = max(15, min(60, $earliestRetryAfter ?? 30));

            throw new GeminiRateLimitedException(
                'All configured Gemini models are temporarily rate limited (429/RPM). Retry after '.$retry.'s.',
                $retry,
            );
        }

        throw new RuntimeException('All configured Gemini models failed or exhausted their daily quota.');
    }

    /**
     * ONE HTTP request to a specific model. Auth in the x-goog-api-key header
     * (never ?key= in the query string). http_errors=false so the waterfall
     * engine inspects every status itself; network failures still throw
     * ConnectionException for the engine to catch.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postToModel(string $modelName, array $payload, string $apiKey): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])
            ->withOptions([
                'http_errors' => false,
            ])
            ->post(self::BASE_URL.'/'.$modelName.':generateContent', $payload);
    }

    /**
     * Circuit-breaker cooldown. The reason string is the contract value
     * ('unsupported', '429_rpm', ...); the companion *_until key carries the
     * expiry timestamp so gemini:models-status can display remaining seconds
     * (the cache store has no generic TTL-read API).
     */
    private function putCooldown(string $modelName, string $reason, int $seconds): void
    {
        Cache::put("gemini_cooldown:{$modelName}", $reason, $seconds);
        Cache::put("gemini_cooldown_until:{$modelName}", time() + $seconds, $seconds);
    }

    /**
     * Logging must never break the pipeline (read-only log file, full disk).
     *
     * @param  array<string, mixed>  $context
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (Throwable) {
            // Logging unavailable — carry on.
        }
    }

    /**
     * Extract structured JSON from Gemini candidate response.
     *
     * Gemini with responseMimeType=application/json returns:
     * { candidates: [ { content: { parts: [ { text: "{...json...}" } ] } } ] }
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function extractJsonFromCandidate(array $raw): array
    {
        try {
            // Direct structured path
            $candidates = $raw['candidates'] ?? null;

            if (is_array($candidates) && isset($candidates[0]['content']['parts'][0]['text'])) {
                $text = (string) $candidates[0]['content']['parts'][0]['text'];
                $decoded = json_decode($text, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorMsg = json_last_error_msg();
                    Log::error('Gemini candidate text is not valid JSON.', [
                        'text_snippet' => substr($text, 0, 2000),
                        'json_error' => $errorMsg,
                    ]);

                    throw new RuntimeException('Gemini candidate returned invalid JSON: '.$errorMsg);
                }

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini candidate JSON is not an object.');
                }

                return $decoded;
            }

            // Fallback: if API already returned object directly (mocked in tests)
            if (isset($raw['is_flyer']) || isset($raw['items']) || isset($raw['products'])) {
                return $raw;
            }

            Log::error('Unexpected Gemini response shape.', [
                'keys' => array_keys($raw),
                'raw_snippet' => substr((string) json_encode($raw), 0, 2000),
            ]);

            throw new RuntimeException('Unexpected Gemini response shape: missing candidates[0].content.parts[0].text');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Failed to extract JSON from candidate.', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to extract JSON from Gemini response: '.$e->getMessage(), 0, $e);
        }
    }

    private function apiKey(): string
    {
        // Config-only by contract: env() is resolved once in config/services.php.
        $key = trim((string) config('services.gemini.key', ''));

        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        return $key;
    }

    /**
     * Fetch image URL as inlineData part (base64). Returns null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function fetchImageAsInlineData(string $url): ?array
    {
        try {
            // SSRF guard BEFORE any byte is fetched: webhook-supplied URLs must
            // never probe loopback/private/reserved origins or internal metadata.
            PublicUrlGuard::assertPublicHttpUrl($url);

            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Magalatna-GeminiVision/1.0',
                    'Accept' => 'image/*',
                ])
                ->withOptions([
                    // Redirect SSRF guard: every 30x hop is re-validated — a
                    // redirect must never pivot into loopback/private/reserved
                    // IP ranges or internal hostnames.
                    'allow_redirects' => [
                        'max' => 5,
                        'on_redirect' => static function ($request, $response, $uri): void {
                            PublicUrlGuard::assertPublicHttpUrl((string) $uri);
                        },
                    ],
                    'http_errors' => false,
                    // Abort inside Guzzle's on_headers hook BEFORE the body
                    // streams into worker memory.
                    'on_headers' => static function (ResponseInterface $headers): void {
                        $contentLength = (int) ($headers->getHeaderLine('Content-Length') ?: 0);

                        if ($contentLength > self::MAX_INLINE_IMAGE_BYTES) {
                            throw new RuntimeException('Image exceeds maximum allowed size (Content-Length).');
                        }
                    },
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to fetch image for classification.', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $binary = $response->body();

            if ($binary === '') {
                return null;
            }

            // Re-check after download: chunked responses lack Content-Length.
            if (strlen($binary) > self::MAX_INLINE_IMAGE_BYTES) {
                Log::warning('Classification image exceeds size cap, skipping.', [
                    'url' => $url,
                    'bytes' => strlen($binary),
                ]);

                return null;
            }

            $mime = $this->detectMimeType($binary);
            // Fallback to response header mime if detection is generic
            $headerMime = $response->header('Content-Type');
            if (is_string($headerMime) && str_starts_with($headerMime, 'image/')) {
                $mime = $headerMime;
            }

            return [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => base64_encode($binary),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Exception fetching image for classification.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function detectMimeType(string $binary): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($binary);

            if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
                return $mime;
            }
        } catch (Throwable $e) {
            Log::warning('finfo mime detection failed.', ['error' => $e->getMessage()]);
        }

        // Heuristic fallback for common cases
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($binary, "\x89PNG")) {
            return 'image/png';
        }

        if (str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 8, 4), 'WEBP')) {
            return 'image/webp';
        }

        return 'image/jpeg';
    }

    /**
     * Normalize a date-ish string to the Arabic "d month year" format used in
     * prompts. Non-ISO inputs pass through untouched (Gatekeeper callers may
     * already send pre-formatted Arabic dates).
     */
    private function formatArabicDate(?string $date): string
    {
        $date = trim((string) $date);

        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $date;
        }

        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        try {
            $carbon = Carbon::createFromFormat('Y-m-d', $date, 'Africa/Cairo');
        } catch (Throwable) {
            return $date;
        }

        if ($carbon === false) {
            return $date;
        }

        return $carbon->day.' '.$months[(int) $carbon->month].' '.$carbon->year;
    }

    /**
     * BLUF Summary prompt — strict Arabic only, 2 sentences, high-CTR atomic quotable (2026 SEO).
     */
    private function blufPrompt(string $retailerName, string $title, string $validFrom, string $validUntil, array $topProducts, float $maxDiscount, ?string $occasion = null): string
    {
        $productsList = $topProducts !== [] ? implode('، ', array_slice($topProducts, 0, 5)) : 'سلع متنوعة';
        // Occasion is ignored for strict 2-sentence schema — theme is already in title

        return <<<PROMPT
اكتب ملخصاً صحفياً تسويقياً باللغة العربية الفصحى المبسطة في جملتين فقط (BLUF Summary) موجه للمستهلك المصري يحقق أعلى CTR لـ Google AI Overviews و Perplexity.

الصيغة الصارمة — جملتان فقط:
الجملة 1: "تصفح {$title} الساري في مصر حتى {$validUntil}، بخصومات تصل إلى {$maxDiscount}%."
الجملة 2: "يشمل العرض تخفيضات قوية على {$productsList} بجميع الفروع وحتى نفاذ الكمية."

قواعد صارمة:
- استخدم التاريخ باليوم والشهر العربي كما هو: {$validUntil}
- اذكر نسبة الخصم {$maxDiscount}% بدقة
- اذكر 3-5 منتجات استراتيجية بالاسم من: {$productsList}
- يُمنع منعاً باتاً كتابة أي كلمة بالإنجليزية، أي أرقام إنجليزية إضافية خارج التاريخ والنسبة، أو ذكر أي مواقع/منافسين
- لا تزد عن جملتين، كل جملة تنتهي بنقطة
PROMPT;
    }

    /**
     * Generate BLUF summary via Gemini — strict Arabic, 2 sentences.
     *
     * @return string Arabic 2-sentence summary, no English
     *
     * @throws RuntimeException
     */
    public function generateBlufSummary(string $retailerName, string $title, string $validFrom, string $validUntil, int $totalPages, array $topProducts = [], float $maxDiscount = 0.0, ?string $occasion = null): string
    {
        $apiKey = $this->apiKey();
        // Normalize ISO inputs to the Arabic day-month-year format the prompt
        // demands, so callers passing raw Y-m-d strings get consistent output.
        $validFrom = $this->formatArabicDate($validFrom);
        $validUntil = $this->formatArabicDate($validUntil);
        // Nearest-integer discount: Gemini echoes the number we give it — a
        // "24.69%" input would be emitted verbatim and quoted by AI answer
        // engines. Same rounding rule as the deterministic fallback builders.
        $maxDiscount = (float) round($maxDiscount);
        $prompt = $this->blufPrompt($retailerName, $title, $validFrom, $validUntil, $topProducts, $maxDiscount, $occasion);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.95,
                'topK' => 40,
                'responseMimeType' => 'text/plain',
            ],
        ];

        $raw = $this->callGeminiRawText($payload, $apiKey);
        $text = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Strip straight AND curly quotes plus their HTML entities — curly
        // variants otherwise survive into titles and break JSON-LD rendering.
        $text = str_replace(['"', '“', '”', '„', '«', '»', '&quot;', '&#34;', '&#x22;'], '', $text);

        // Allow Latin brand acronyms/names while rejecting pure English sentences
        $arabicCount = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $totalLetters = preg_match_all('/\p{L}/u', $text);
        $arabicRatio = $totalLetters > 0 ? ($arabicCount / $totalLetters) : 0;

        // Reject only if Arabic content is less than 75% or contains prominent English filler words
        if ($arabicRatio < 0.75 || preg_match('/\b(discover|browse|available|valid from|shop now|terms and conditions)\b/i', $text)) {
            Log::warning('Gemini BLUF does not meet Arabic ratio requirements.', ['text' => $text, 'arabicRatio' => $arabicRatio]);
            throw new RuntimeException('Summary does not meet Arabic ratio requirements.');
        }

        // Ensure 2 sentences (split by Arabic full stop). (?<!\d)…(?!\d): a
        // dot BETWEEN digits is a decimal separator (37.55%) — never a
        // sentence boundary, so the 2nd sentence is no longer truncated.
        $sentences = preg_split('/(?<!\d)[.!؟。]+(?!\d)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences);
        if (count($sentences) > 2) {
            $text = implode('. ', array_slice($sentences, 0, 2)).'.';
        }

        // Fix space bugs in numbers: "37. 55%" -> "37.55%"
        $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);
        $text = (string) preg_replace('/(\d+)\s+%/u', '$1%', $text);

        return $text;
    }

    /**
     * Editorial Overview prompt — Strict Structured Bulleted Highlights (150 words, no fluff, numerals only)
     */
    private function editorialPrompt(string $retailerName, string $title, string $validFrom, string $validUntil, array $heroDeals, string $discountRange): string
    {
        $dealsText = $heroDeals !== [] ? implode("\n", array_map(static fn ($d, $i) => ($i + 1).". {$d}", $heroDeals, array_keys($heroDeals))) : 'لا توجد بيانات منتجات';

        return <<<PROMPT
اكتب مقالاً تحريرياً باللغة العربية الفصحى المبسطة (150 كلمة، فقرتان) بدون أي حشو تسويقي أو نثر خيالي لعرض {$title} من {$retailerName}.

يُمنع منعاً باتاً:
- العبارات الإنشائية والشاعرية مثل "يلتقي الذكاء الشرائي"، "الصفقات البطولية"، "دقات الساعة"
- كتابة الأرقام بالحروف مثل "سبعة وأربعين جنيهاً" — يجب استخدام الأرقام العربية القياسية فقط مثل "47.95 ج.م"، "خصم 19%"، "توفير 11.00 ج.م"

الصيغة الصارمة — نقاط مرقمة:
أبرز نقاط عرض {$retailerName} ({$validFrom} حتى {$validUntil}):
- أعلى نسبة خصم: [اسم المنتج والوزن] بسعر [السعر الحالي] ج.م (خصم [النسبة]% وتوفير [مبلغ التوفير] ج.م).
- [تصنيف السلع، مثل: سلع البقالة الأساسية / المنظفات / مستلزمات الأطفال]: [اسم المنتج] بسعر [السعر الحالي] ج.م (بدلاً من [السعر القديم] ج.م).
- [منتجات مميزة أخرى]: [اسم المنتج] بسعر [السعر الحالي] ج.م بخصم [النسبة]%.
- مدة العرض: [ساري اليوم فقط / من {$validFrom} حتى {$validUntil}] بجميع فروع {$retailerName} بمصر حتى نفاذ الكمية.

استخدم البيانات التالية بدقة (الأسعار والنسب كما هي، بدون مسافات حول النقطة العشرية):
{$dealsText}
الخصومات العامة: {$discountRange}

قواعد:
- لا إنجليزية، لا مواقع أخرى، لا شعر
- كل الأسعار والخصومات بالأرقام فقط: "47.95 ج.م" وليس "سبعة وأربعين"
- التزم بالهيكل النقطي أعلاه حرفياً
PROMPT;
    }

    /**
     * Generate Editorial Overview via Gemini — 150 words, 2 paragraphs
     *
     * @throws RuntimeException
     */
    public function generateEditorialOverview(Flyer $flyer): string
    {
        $apiKey = $this->apiKey();
        $retailerName = $flyer->retailer?->name ?? $flyer->retailer()->first()?->name ?? 'المتجر';
        $title = $flyer->title ?? 'العرض';
        $validFrom = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->format('Y-m-d') : (string) $flyer->valid_from;
        $validUntil = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->format('Y-m-d') : (string) $flyer->valid_until;
        // Consistent Arabic date format in the prompt (same as BLUF)
        $validFrom = $this->formatArabicDate($validFrom);
        $validUntil = $this->formatArabicDate($validUntil);

        // Trim ".00"/trailing zeros AFTER 2-decimal formatting: "47.00" → "47",
        // "47.50" → "47.5" — the model copies prices verbatim into the copy.
        $formatPrice = static function ($value): string {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        };

        // Build hero deals with exact prices — discounted items ONLY
        // (an item without a markdown is not a "hero deal" / top saving).
        $heroDeals = $flyer->items()
            ->where('sale_price', '>', 0)
            ->orderByDesc('discount_percent')
            ->limit(4)
            ->get()
            ->map(function ($item) use ($formatPrice) {
                $old = $item->old_price ? $formatPrice($item->old_price).' ج.م' : null;
                $sale = $formatPrice($item->sale_price).' ج.م';
                $discount = $item->discount_percent ? round((float) $item->discount_percent).'%' : '';
                $oldPart = $old ? " بدلاً من {$old} بخصم {$discount}" : '';

                return "{$item->product_name} بسعر {$sale}{$oldPart}";
            })->all();

        // Round the max discount to the NEAREST INTEGER: "تصل إلى 24.58%"
        // reads like a fake-precision machine output — shoppers and AI
        // answer engines quote clean "25%". Same rule as the BLUF builders.
        $discountRange = 'تصل إلى '.round((float) ($flyer->items()->where('sale_price', '>', 0)->max('discount_percent') ?? 0)).'%';

        $prompt = $this->editorialPrompt($retailerName, $title, $validFrom, $validUntil, $heroDeals, $discountRange);

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'topP' => 0.95, 'topK' => 40, 'responseMimeType' => 'text/plain'],
        ];

        $raw = $this->callGeminiRawText($payload, $apiKey);
        $text = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Strip straight AND curly quotes plus their HTML entities
        $text = str_replace(['"', '“', '”', '„', '«', '»', '&quot;', '&#34;', '&#x22;'], '', $text);
        // Allow Latin brand acronyms/names while rejecting pure English sentences
        $arabicCount = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $totalLetters = preg_match_all('/\p{L}/u', $text);
        $arabicRatio = $totalLetters > 0 ? ($arabicCount / $totalLetters) : 0;

        if ($arabicRatio < 0.75 || preg_match('/\b(discover|browse|available|valid from|shop now|terms and conditions)\b/i', $text)) {
            Log::warning('Gemini Editorial does not meet Arabic ratio requirements.', ['text' => $text, 'arabicRatio' => $arabicRatio]);
            throw new RuntimeException('Summary does not meet Arabic ratio requirements.');
        }
        // Ensure 2 paragraphs
        $paragraphs = preg_split('/\n\s*\n/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($paragraphs) > 2) {
            $text = implode("\n\n", array_slice($paragraphs, 0, 2));
        }
        // Fix number spaces
        $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);

        return $text;
    }

    /**
     * Plain-text generation through the SAME ordered waterfall + circuit
     * breaker as vision/OCR. ReelScriptService (reel scripts + TikTok
     * captions) uses this instead of raw Http::post, so every Gemini text
     * call shares one failover engine and one set of per-model quotas.
     *
     * @throws RuntimeException
     * @throws GeminiRateLimitedException
     */
    public function generatePlainText(string $prompt, float $temperature = 0.7): string
    {
        $apiKey = $this->apiKey();

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'topP' => 0.95,
                'responseMimeType' => 'text/plain',
            ],
        ];

        return $this->callGeminiRawText($payload, $apiKey);
    }

    /**
     * Raw-text call (BLUF / editorial / reel copy) through the ordered model
     * waterfall. Auth travels in the `x-goog-api-key` header — never `?key=`
     * in the query string. Errors NEVER include the API key.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     * @throws GeminiRateLimitedException
     */
    private function callGeminiRawText(array $payload, string $apiKey): string
    {
        $result = $this->executeWithModelFallback(
            fn (string $modelName): Response => $this->postToModel($modelName, $payload, $apiKey),
            false,
        );

        /** @var string $result */
        return $result;
    }

    /**
     * Classification prompt with Egyptian retail domain knowledge — hard-negative
     * constraints (installment/app/single-product ads), Flash 1-Day aware, teaser-proof, no date invention.
     */
    private function classificationPrompt(int $imageCount = 1): string
    {
        $today = now('Africa/Cairo')->format('Y-m-d');
        // Dynamic year — a hardcoded year silently misclassifies every post
        // dated January of the following year (the "2027 timebomb").
        $currentYear = now('Africa/Cairo')->year;

        return <<<PROMPT
You are the STRICT Gatekeeper for مجلاتنا (Egyptian retail flyer aggregator).

Current date (Africa/Cairo): {$today}. Assume year {$currentYear} if year not explicitly visible in post/image.
TOTAL_IMAGES_IN_POST: {$imageCount}.

Your primary mission is to identify REAL MULTI-PRODUCT RETAIL FLYERS and AGGRESSIVELY REJECT marketing noise.

A REAL FLYER MUST SATISFY ALL OF THE FOLLOWING:
1. It is a price catalog/brochure featuring MULTIPLE grocery/retail items with explicit numeric prices.
2. It has an explicit start and end date (or a single specific day for Flash deals).
3. If TOTAL_IMAGES_IN_POST == 1: It MUST be an official printed catalog page containing at least 2 distinct products with prices printed directly on the image graphic.

STRICT DISQUALIFICATION RULES — YOU MUST RETURN is_flyer = false FOR ANY OF THE FOLLOWING:
1. INSTALLMENT & FINANCING ADS (عروض التقسيط / البنوك / فاليو / فيزا / بدون فوائد / كاش باك): Any post advertising installment plans or bank/finance promotions (e.g., "تقسيط كارفور فرايداي", ValU, بطاقات بنكية, بدون فوائد).
2. APP & HOTLINE PROMOTIONS (تحميل التطبيق / الخط الساخن / لينكات التليجرام والواتساب / المتجر الإلكتروني): Any post promoting mobile apps, hotlines, or ordering/download channels (e.g., El Fergany hotline promo, "حمل التطبيق", App Store/Google Play links).
3. SINGLE-PRODUCT ADS & BRAND SPONSORSHIPS: Any post advertising only one specific brand or product (e.g., "اشتري منتجات فلفيتا", "مسحوق إريال بأوفر سعر", "شوكولاتة كيت كات") without a multi-item price catalog. EXCEPTION: a multi-image Flash album as defined below is NOT a single-product ad.
4. CONTESTS & GIVEAWAYS: Posts announcing raffles, winners, gold coins, or shopping vouchers (e.g., "سحب على ذهب وفلوس", "قسيمة شراء بـ 2000 جنيه", "مسابقة").
5. TEASERS & BLANK BANNERS: "قريباً", "انتظرونا", "أقوى العروض جاية", "قبل ما العروض تخلص", or generic announcement posters/reminders without product prices or a full catalog.
6. PROMO CODES & APP COUPONS (أكواد الخصم / كوبونات / افتح الأبلكيشن وانسخ الكود / بروموكود): Any post announcing app discount codes or online coupon vouchers (e.g. "انسخ اكواد الخصم", "عروض 11.11 أونلاين", "خصم 11% لحد 300 جنيه") without a multi-item price catalog with individual printed prices.

FLASH 1-DAY ALBUM EXCEPTION (STILL VALID):
An album with TOTAL_IMAGES_IN_POST >= 2 where each image features a discounted staple commodity for a single specific day (e.g., "ساري يوم الأربعاء 9 سبتمبر فقط") IS A VALID FLYER (is_flyer = true). The album as a whole is the catalog — set both valid_from and valid_until to that exact same date.

NOT A FLYER (ALSO REJECT): Contest/giveaway ("مسابقة", "اربح", "جائزة"), recipe ("وصفة"), branch opening/announcement ("افتتاح فرع جديد", "مواعيد العمل"), meme/joke, job ad, political/news, single product ad WITHOUT catalog context (single image, one product, no date — unless part of a multi-image Flash album as defined above).

Instructions:
- is_flyer = true ONLY if it contains an actual verifiable multi-product price catalog OR a valid Flash album as defined above.
- reason = concise Arabic explanation (1 sentence) why it was accepted or rejected.
- flyer_title = STRICT Title formulas (ANTI-CANNIBALIZATION): NEVER return shallow titles like "عروض كازيون". Keep under 65 chars, front-load brand and dates.
  * FORMULA A (Single-Day / Flash): "عروض [اسم المتجر] [اسم اليوم بالعربية] [رقم اليوم] [اسم الشهر بالعربية] [السنة] | عرض اليوم الواحد" — e.g. "عروض كازيون الأربعاء 9 سبتمبر {$currentYear} | عرض اليوم الواحد"
  * FORMULA B (Multi-Day): "عروض [اسم المتجر] من [X] حتى [Y] [اسم الشهر بالعربية] [السنة] | [المناسبة]" — e.g. "عروض كارفور من 10 حتى 20 سبتمبر {$currentYear} | مجلة العروض والتوفير"
- valid_from / valid_until = Extract ONLY explicit, verifiable dates stated in caption or image. Normalize to YYYY-MM-DD (year {$currentYear} if year not visible; today is {$today}). NEVER invent, estimate, or hallucinate dates. If unsure, or if the post is an installment/app/single-product promo, return is_flyer = false.
- EGYPTIAN RETAIL TEMPORAL PARSING — MUST explicitly support all 4 core structures:
  1. Single-Day Flash Deals ("يوم السبت 12 سبتمبر فقط"): valid_from = valid_until = that exact day.
  2. Two-Day Weekend Offers ("يوم الجمعة 11 سبتمبر ويوم السبت 12 سبتمبر"): valid_from="{$currentYear}-09-11", valid_until="{$currentYear}-09-12".
  3. Cross-Month Campaigns ("من 11 سبتمبر حتى 1 أكتوبر"): increment the month for end dates.
  4. Open-ended with Start Date ("ساري من 10 سبتمبر وحتى نفاذ الكمية"): Calculate valid_until as valid_from + 10 days.
     EXCEPTION — "Until Stock Lasts" (حتى نفاذ الكمية): if the post contains a clear explicit START date (valid_from) AND the phrase "حتى نفاذ الكمية" (variants: "حتى نفاد الكمية", "حتي نفاذ الكميه", "until stock lasts"), you MAY accept it as a valid flyer (is_flyer = true) — BUT ONLY if it ALSO passes the REAL FLYER multi-product catalog test above; brand promos and single-product ads remain rejected (is_flyer = false). If BOTH valid_from and valid_until are missing/cannot be extracted, you MUST reject — no exception applies.
=== EGYPTIAN SLANG & COLLOQUIAL DATE RULES ===
- "إنهاردة" / "النهارده" means "Today" ({$today}); "بكرة" / "بكره" means "Tomorrow".
- If text says "من إنهاردة وحتى 16-9-{$currentYear}", valid_from = "{$today}", valid_until = "{$currentYear}-09-16".
- If text says "إنهاردة وبكرة الإثنين والثلاثاء 7 و 8 سبتمبر", valid_from = "{$currentYear}-09-07", valid_until = "{$currentYear}-09-08".
- Never reject a post simply because it uses colloquial Egyptian date phrasing like "من إنهاردة".
=== GOVERNORATES ===
- applicable_governorates = List of Egyptian governorates mentioned (Arabic names e.g., "القاهرة", "الجيزة", "الإسكندرية" or English "Cairo","Giza"). Return [] if applies to all Egypt / no restriction mentioned.

Be strict: If unsure, or if no explicit date is verifiable (and no "حتى نفاذ الكمية" with a clear start date on a valid catalog), prefer is_flyer=false. Never invent dates.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_flyer' => [
                    'type' => 'boolean',
                    'description' => 'True if this is a genuine retail flyer/catalog with multiple price offers.',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Concise reason for classification.',
                ],
                'flyer_title' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Short title extracted from post, or null if not a flyer.',
                ],
                'valid_from' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Start date in YYYY-MM-DD or null.',
                    // 'format' => 'date', // optional strict
                ],
                'valid_until' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'End date in YYYY-MM-DD or null.',
                ],
                'applicable_governorates' => [
                    'type' => 'array',
                    'description' => 'Governorates where flyer applies, empty if nationwide.',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'required' => ['is_flyer', 'reason', 'flyer_title', 'valid_from', 'valid_until', 'applicable_governorates'],
            'propertyOrdering' => ['is_flyer', 'reason', 'flyer_title', 'valid_from', 'valid_until', 'applicable_governorates'],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{is_flyer: bool, reason: string, flyer_title: ?string, valid_from: ?string, valid_until: ?string, applicable_governorates: string[]}
     */
    private function normalizeClassificationResult(array $decoded): array
    {
        $isFlyer = (bool) ($decoded['is_flyer'] ?? false);
        $reason = (string) ($decoded['reason'] ?? ($isFlyer ? 'Classified as flyer' : 'Not a flyer'));
        $title = $decoded['flyer_title'] ?? null;
        $validFrom = $decoded['valid_from'] ?? null;
        $validUntil = $decoded['valid_until'] ?? null;
        $govs = $decoded['applicable_governorates'] ?? [];

        // Normalize nullable strings
        $title = is_string($title) && trim($title) !== '' ? trim($title) : null;
        $validFrom = $this->normalizeDateOrNull($validFrom);
        $validUntil = $this->normalizeDateOrNull($validUntil);

        if (! is_array($govs)) {
            $govs = [];
        }
        $govs = array_values(array_filter(array_map(fn (mixed $v): string => trim((string) $v), $govs), fn (string $v): bool => $v !== ''));

        // If not a flyer, force null dates/title per spec hygiene
        if (! $isFlyer) {
            // Keep reason, but title/dates should be null unless model incorrectly returned them
            // We keep as-is for debugging but normalize to null if needed: uncomment next lines if strict
            // $title = null; $validFrom = null; $validUntil = null;
        }

        return [
            'is_flyer' => $isFlyer,
            'reason' => $reason,
            'flyer_title' => $title,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'applicable_governorates' => $govs,
        ];
    }

    private function normalizeDateOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
        if ($str === '' || strtolower($str) === 'null') {
            return null;
        }

        // Validate YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) === 1) {
            $ts = strtotime($str);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }

        // Try to parse other formats
        $ts = strtotime($str);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        Log::warning('Invalid date format from Gemini classification.', ['value' => $value]);

        return null;
    }

    private function ocrPrompt(): string
    {
        return <<<'PROMPT'
You are an expert OCR for Egyptian supermarket flyers/catalogs (High-Density Page OCR).

Extract EVERY product offer visible in the flyer page image. Flyers are in Arabic and sometimes English, high density, small fine print.

STRICT ZERO-PRICE RULE:
DO NOT output any item that does not have an explicit, printed numeric price > 0.
If an image shows products with generic promo text (e.g. "خصم 11%", "وفر معنا") but NO printed item-by-item prices, return an empty items array: {"items": []}.
NEVER return 0, 0.0, or null for sale_price.

CRITICAL PRICE RULE — READ CAREFULLY:
Supermarkets print prices with SUPERSCRIPTS for piasters/qirsh.
Example: A large "19" and a small superscript "95" means 19.95 EGP, NOT 1995.
Example: large "49" + small "50" = 49.50 EGP.
Example: large "10" + small "95" stacked = 10.95.
You MUST combine integer + superscript fractional as float: 19.95.
NEVER output 1995 when you see 19 and 95 in different font sizes. Always use decimal point.
If only one number is present (e.g., "20 جنيه"), return 20.0.
Currency is EGP; ignore "جنيه" text.

OLD PRICE vs SALE PRICE:
- If you see a crossed-out faded price (often struck-through, smaller, or grey) plus a bold colored price, then:
  old_price = crossed-out value (float with same superscript rule), sale_price = bold price.
- If only one price exists, old_price = null.
- Never swap them.

For each distinct product bounding box/card, return:
- product_name: MUST ALWAYS be written in Arabic! If a flyer tile only prints English text (e.g., "Balady Beef Escalope", "Dragon Fruit", "Cantaloupe"), translate it into natural Egyptian Supermarket Arabic ("اسكالوب بقري بلدي", "دراجون فروت", "كانتلوب"). Never return pure-English product_name. Include size/weight if shown, e.g., "أرز مصري 5 كجم".
- sale_price: float (EGP) after applying superscript rule.
- old_price: nullable float (same rule) if crossed-out price exists.
- unit: nullable string normalized: one of "كجم","جرام","لتر","مل","قطعة","علبة","كرتونة","حبة","كيس" or null if not stated. Infer from packaging text (e.g., "1 لتر", "500 جم").
- category: nullable string — classify the product into EXACTLY ONE of these fixed Egyptian-retail categories: "oils_ghee" (cooking oils, ghee, butter), "dairy_cheese" (milk, cheese, yogurt, cream), "meat_poultry" (chicken, meat, fish, tuna, frozen), "groceries" (rice, sugar, flour, pasta, lentils, salt), "beverages_snacks" (juice, soda, tea, coffee, water, biscuits, chocolate, chips, gum), "cleaning_laundry" (detergents, dish soap, bleach, tissues), "personal_care" (shampoo, soap bars, toothpaste, diapers, sanitary pads, hair oils), "home_appliances" (cookware, fridges, washing machines, mixers, fans, blankets), "automotive_tools" (motor oil, car batteries, wipers, power tools), or "other". Motor oil / car products are "automotive_tools", NOT cooking oil. Hair "بديل زيت" is "personal_care", NOT cooking oil.
- brand_name: nullable brand as printed (e.g., "الضحى", "جهينة", "Nestle") or null.
- bundle_condition: nullable string for conditions like "حد أقصى 2 قطعة للعميل", "العرض حتى نفاذ الكمية", "2+1", or null.
- extra_attributes: object of additional key-values (e.g., {"weight":"5 كجم","origin":"مصري","flavor":"فراولة"}). Return {} if none.

Output pure JSON per schema. Do not add commentary. Ensure all floats have decimal point. Be exhaustive — extract ALL offers, even small ones in corners.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function ocrSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'All product offers found on the page.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_name' => [
                                'type' => 'string',
                                'description' => 'Exact product name as printed.',
                            ],
                            'sale_price' => [
                                'type' => 'number',
                                'description' => 'Current sale price in EGP (float), with superscript fix.',
                            ],
                            'old_price' => [
                                'type' => 'number',
                                'nullable' => true,
                                'description' => 'Crossed-out old price or null.',
                            ],
                            'unit' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'Unit like كجم, لتر, قطعة, or null.',
                            ],
                            'category' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'One fixed Egyptian-retail category: oils_ghee, dairy_cheese, meat_poultry, groceries, beverages_snacks, cleaning_laundry, personal_care, home_appliances, automotive_tools, or other.',
                            ],
                            'brand_name' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'Brand name or null.',
                            ],
                            'bundle_condition' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'Bundle/max condition or null.',
                            ],
                            'extra_attributes' => [
                                'type' => 'object',
                                'nullable' => true,
                                'description' => 'Additional key-values, empty object if none.',
                            ],
                        ],
                        'required' => ['product_name', 'sale_price', 'old_price', 'unit', 'category', 'brand_name', 'bundle_condition', 'extra_attributes'],
                        'propertyOrdering' => ['product_name', 'sale_price', 'old_price', 'unit', 'category', 'brand_name', 'bundle_condition', 'extra_attributes'],
                    ],
                ],
            ],
            'required' => ['items'],
            'propertyOrdering' => ['items'],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{product_name: string, sale_price: float, old_price: ?float, unit: ?string, category: string, brand_name: ?string, bundle_condition: ?string, extra_attributes: array<string, string>}>
     */
    private function normalizeOcrItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $idx => $raw) {
            if (! is_array($raw)) {
                Log::warning('Skipping non-array OCR item.', ['index' => $idx]);

                continue;
            }

            $productName = trim((string) ($raw['product_name'] ?? ''));
            if ($productName === '') {
                Log::warning('Skipping OCR item without product_name.', ['index' => $idx]);

                continue;
            }

            $salePrice = $this->toFloatOrNull($raw['sale_price'] ?? null);
            // حظر تام لأي سعر صفري أو سالب (Supermarket items cannot be 0.00 EGP)
            if ($salePrice === null || $salePrice <= 0) {
                Log::warning('Skipping OCR item with zero or invalid sale_price.', [
                    'index' => $idx,
                    'product' => $productName,
                    'price' => $salePrice,
                ]);

                continue;
            }

            // Guard against superscript misread: if old_price is >10x sale_price, likely 1995 vs 19.95 error
            $oldPrice = $this->toFloatOrNull($raw['old_price'] ?? null);
            if ($oldPrice !== null && $oldPrice > $salePrice * 10) {
                Log::warning('Suspicious old_price much larger than sale_price (possible superscript misread).', [
                    'product' => $productName,
                    'sale_price' => $salePrice,
                    'old_price' => $oldPrice,
                ]);
            }

            $unit = isset($raw['unit']) && is_string($raw['unit']) && trim($raw['unit']) !== '' ? trim((string) $raw['unit']) : null;
            $brand = isset($raw['brand_name']) && is_string($raw['brand_name']) && trim($raw['brand_name']) !== '' ? trim((string) $raw['brand_name']) : null;
            $bundle = isset($raw['bundle_condition']) && is_string($raw['bundle_condition']) && trim($raw['bundle_condition']) !== '' ? trim((string) $raw['bundle_condition']) : null;

            // AI category is only a HINT: the deterministic Egyptian-retail
            // keyword classifier (ProductDataNormalizer::inferCategory) has
            // priority and guards the closed category vocabulary.
            $aiCategory = isset($raw['category']) && is_string($raw['category']) && trim($raw['category']) !== ''
                ? trim((string) $raw['category'])
                : null;
            $category = ProductDataNormalizer::inferCategory($productName, $aiCategory);

            $extra = $raw['extra_attributes'] ?? [];
            if (! is_array($extra)) {
                $extra = [];
            }
            // Ensure all extra values are strings
            $extraClean = [];
            foreach ($extra as $k => $v) {
                $k = trim((string) $k);
                if ($k === '') {
                    continue;
                }
                $extraClean[$k] = trim((string) $v);
            }

            $normalized[] = [
                'product_name' => $productName,
                'sale_price' => (float) $salePrice,
                'old_price' => $oldPrice !== null ? (float) $oldPrice : null,
                'unit' => $unit,
                'category' => $category,
                'brand_name' => $brand,
                'bundle_condition' => $bundle,
                'extra_attributes' => $extraClean,
            ];
        }

        return $normalized;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $v = trim($value);
            // Remove currency symbols, commas
            $v = str_replace([',', 'جنيه', 'EGP', 'ج.م', 'جم'], '', $v);
            $v = trim($v);
            if ($v === '' || strtolower($v) === 'null') {
                return null;
            }
            if (is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }
}
