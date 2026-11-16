# Phase-2 Audit Bundle — magalatna.com

Snapshot of every file requested for the Phase-2 SEO / Schema / Security audit,
captured after the zero-regression hardening refactor (74 tests / 214 assertions, all green).

> **`app/Http/Controllers/SitemapController.php` DOES NOT EXIST.**
> There is no sitemap controller in the codebase. `/sitemap.xml` is served by a
> route **closure** in `routes/web.php` (section 1 below, lines 38-70), rendered
> from the `resources/views/sitemap.php` Blade view and cached 6h under the
> `sitemap_xml_content` key (invalidated by `FlyerObserver`).

Files included in this bundle:
- `routes/web.php`
- `routes/api.php`
- `app/Services/GeminiVisionService.php`
- `app/Services/CloudflareCacheService.php`
- `app/Services/ImageOptimizerService.php`
- `app/Filament/Resources/FlyerResource.php`
- `resources/views/flyers/show.blade.php`

---

## 1) routes/web.php

````php
<?php

declare(strict_types=1);

use App\Enums\FlyerStatus;
use App\Http\Controllers\FlyerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\RetailerController;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Static legal/informational pages — MUST be before catch-all retailer route
Route::get('/about-us', [PageController::class, 'about'])->name('pages.about');
Route::get('/privacy-policy', [PageController::class, 'privacy'])->name('pages.privacy');
Route::get('/terms-of-use', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/contact-us', [PageController::class, 'contact'])->name('pages.contact');
Route::get('/offers/{slug}', [FlyerController::class, 'show'])->name('flyers.show');

// Redirect old URLs for SEO preservation
Route::get('/flyer/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));
Route::get('/flyers/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));

// Legacy retailer aliases -> canonical slugs (301 to preserve link equity)
Route::permanentRedirect('/bim', '/bimmisr');
Route::permanentRedirect('/carrefour', '/carrefouregypt');

Route::get('/llms.txt', [LlmsTxtController::class, 'index'])->name('llms.txt');

// خريطة الموقع لمحركات البحث — cached 6h, invalidated via observers (FlyerObserver::saved/deleted)
// Memory-guarded: max 300 flyers, column-selected, cover page only (no full pages hydration).
Route::get('/sitemap.xml', function () {
    $xml = Cache::remember('sitemap_xml_content', 21600, function () {
        $retailers = Retailer::where('is_active', true)->get();
        $activeFlyers = Flyer::where('status', FlyerStatus::Published)
            ->select(['id', 'slug', 'retailer_id', 'title', 'updated_at'])
            ->with([
                'retailer' => static fn ($q) => $q->select(['id', 'name', 'slug', 'logo_path']),
                'pages' => static fn ($q) => $q->select(['id', 'flyer_id', 'image_path', 'page_number'])
                    ->where('page_number', 1)
                    ->orderBy('page_number')
                    ->limit(1),
            ])
            ->latest('updated_at')
            ->limit(300)
            ->get();
        $expiredFlyers = Flyer::where('status', FlyerStatus::Expired)
            ->select(['id', 'slug', 'retailer_id', 'updated_at'])
            ->with(['retailer'])
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return view('sitemap', [
            'retailers' => $retailers,
            'activeFlyers' => $activeFlyers,
            'expiredFlyers' => $expiredFlyers,
            // Back-compat for older view variable name
            'flyers' => $activeFlyers,
        ])->render();
    });

    return Response::make($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
})->name('sitemap');

// Retailer Hub — SEO-optimized dedicated pages /{retailer:slug}
// MUST be last to avoid conflict with /offers/{slug}, /admin, /sitemap.xml, /about-us etc.
Route::get('/{retailer:slug}', [RetailerController::class, 'show'])
    ->where('retailer', '^(?!offers$|flyer$|flyers$|admin$|api$|storage$|up$|livewire$|build$|assets$|favicon\.ico$|sitemap\.xml$|llms\.txt$|about-us$|privacy-policy$|terms-of-use$|contact-us$).*')
    ->name('retailers.show');
````

---

## 2) routes/api.php

````php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\RawFacebookIngestController;
use App\Http\Middleware\InternalApiKeyMiddleware;
use App\Http\Middleware\VerifyIngestWebhookSecret;
use Illuminate\Support\Facades\Route;

// Unified ingestion prefix: every POST is authenticated AND throttled (60 RPM).
Route::prefix('v1')->group(function (): void {
    // Exact endpoint targeted by Cloudflare Worker radar: POST /api/v1/facebook/ingest
    Route::post('/facebook/ingest', RawFacebookIngestController::class)
        ->middleware(['ingest.secret', 'throttle:60,1'])
        ->name('api.v1.facebook.ingest');

    Route::prefix('ingest')->group(function (): void {
        // Canonical ingest route
        Route::post('/facebook-raw', RawFacebookIngestController::class)
            ->middleware(['ingest.secret', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook-raw');

        // Short alias
        Route::post('/facebook', RawFacebookIngestController::class)
            ->middleware(['ingest.secret', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook');

        // Optional Sanctum-protected alias (if Sanctum is configured)
        Route::post('/facebook-raw/sanctum', RawFacebookIngestController::class)
            ->middleware(['auth:sanctum', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook-raw.sanctum');
    });

    // Deprecated legacy alias: POST /api/v1/internal/ingest-raw-facebook
    Route::post('/internal/ingest-raw-facebook', RawFacebookIngestController::class)
        ->middleware(['ingest.secret', 'throttle:60,1'])
        ->name('api.v1.internal.ingest-raw-facebook');
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->utc()->toIso8601String()]);
})->name('api.health');
````

---

## 3) app/Services/GeminiVisionService.php

````php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class GeminiVisionService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SECONDS = 60;

    private function model(): string
    {
        $model = (string) (config('services.gemini.model') ?? env('GEMINI_MODEL', 'gemini-2.5-flash'));

        return trim($model) !== '' ? trim($model) : 'gemini-2.5-flash';
    }

    private const MAX_RETRIES = 4;

    private const INITIAL_RETRY_DELAY_MS = 8000;

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
     * @return array<int, array{product_name: string, sale_price: float, old_price: ?float, unit: ?string, brand_name: ?string, bundle_condition: ?string, extra_attributes: array<string, string>}>
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
     * Core Gemini HTTP call with 429 retry and invalid JSON handling.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function callGemini(array $payload, string $apiKey): array
    {
        $url = self::BASE_URL.'/'.$this->model().':generateContent';

        $attempt = 0;
        $delayMs = self::INITIAL_RETRY_DELAY_MS;

        while (true) {
            $attempt++;

            try {
                /** @var Response $response */
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])
                    ->withOptions([
                        'http_errors' => false,
                    ])
                    ->post($url.'?key='.$apiKey, $payload);

                $status = $response->status();

                if ($status === 429) {
                    $retryAfter = (int) $response->header('Retry-After');
                    // Linear backoff scaled to the 60s RPM window:
                    // Attempt 1 = 8s, Attempt 2 = 16s, Attempt 3 = 24s, Attempt 4 = 32s.
                    // Honors server-sent Retry-After when present.
                    $waitMs = $retryAfter > 0 ? $retryAfter * 1000 : ($attempt * self::INITIAL_RETRY_DELAY_MS);

                    Log::warning('Gemini API 429 Too Many Requests.', [
                        'attempt' => $attempt,
                        'retry_after' => $retryAfter,
                        'wait_ms' => $waitMs,
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new RuntimeException('Gemini API rate limited (429) after '.$attempt.' attempts.');
                    }

                    usleep($waitMs * 1000);

                    continue;
                }

                if (! $response->successful()) {
                    $bodySnippet = substr((string) $response->body(), 0, 1000);

                    Log::error('Gemini API non-successful response.', [
                        'status' => $status,
                        'body_snippet' => $bodySnippet,
                        'attempt' => $attempt,
                    ]);

                    // Retry on 5xx transient
                    if ($status >= 500 && $status < 600 && $attempt < self::MAX_RETRIES) {
                        usleep($delayMs * 1000);
                        $delayMs *= 2;

                        continue;
                    }

                    throw new RuntimeException('Gemini API error: HTTP '.$status.' - '.$bodySnippet);
                }

                $body = (string) $response->body();

                if (trim($body) === '') {
                    throw new RuntimeException('Gemini API returned empty body.');
                }

                $json = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorMsg = json_last_error_msg();
                    Log::error('Gemini API returned invalid JSON.', [
                        'body_snippet' => substr($body, 0, 2000),
                        'json_error' => $errorMsg,
                    ]);

                    throw new RuntimeException('Gemini API returned invalid JSON: '.$errorMsg);
                }

                return $this->extractJsonFromCandidate($json);
            } catch (RuntimeException $e) {
                // Re-throw 429 already handled; don't retry if max reached
                if (str_contains($e->getMessage(), '429') && $attempt < self::MAX_RETRIES) {
                    continue;
                }

                throw $e;
            } catch (Throwable $e) {
                Log::error('Gemini HTTP call failed.', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= self::MAX_RETRIES) {
                    throw new RuntimeException('Gemini HTTP call failed after '.$attempt.' attempts: '.$e->getMessage(), 0, $e);
                }

                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
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
        $key = (string) (config('services.gemini.key') ?? env('GEMINI_API_KEY', ''));

        if (trim($key) === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        return trim($key);
    }

    /**
     * Fetch image URL as inlineData part (base64). Returns null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function fetchImageAsInlineData(string $url): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => '3roodNet-GeminiVision/1.0',
                    'Accept' => 'image/*',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
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
        $text = str_replace(['"', '"', '"', '&quot;', '&#34;', '&#x22;'], '', $text);

        // Allow Latin brand acronyms/names while rejecting pure English sentences
        $arabicCount = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $totalLetters = preg_match_all('/\p{L}/u', $text);
        $arabicRatio = $totalLetters > 0 ? ($arabicCount / $totalLetters) : 0;

        // Reject only if Arabic content is less than 75% or contains prominent English filler words
        if ($arabicRatio < 0.75 || preg_match('/\b(discover|browse|available|valid from|shop now|terms and conditions)\b/i', $text)) {
            Log::warning('Gemini BLUF does not meet Arabic ratio requirements.', ['text' => $text, 'arabicRatio' => $arabicRatio]);
            throw new RuntimeException('Summary does not meet Arabic ratio requirements.');
        }

        // Ensure 2 sentences (split by Arabic full stop)
        $sentences = preg_split('/[.!؟。]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
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

        // Build hero deals with exact prices
        $heroDeals = $flyer->items()->orderByDesc('discount_percent')->limit(4)->get()->map(function ($item) {
            $old = $item->old_price ? number_format((float) $item->old_price, 2, '.', '').' ج.م' : null;
            $sale = number_format((float) $item->sale_price, 2, '.', '').' ج.م';
            $discount = $item->discount_percent ? round((float) $item->discount_percent).'%' : '';
            $oldPart = $old ? " بدلاً من {$old} بخصم {$discount}" : '';

            return "{$item->product_name} بسعر {$sale}{$oldPart}";
        })->all();

        $discountRange = 'تصل إلى '.number_format((float) ($flyer->items()->max('discount_percent') ?? 0), 2, '.', '').'%';
        $discountRange = rtrim(rtrim($discountRange, '0'), '.');
        // Fix space bug
        $discountRange = str_replace('. ', '.', $discountRange);

        $prompt = $this->editorialPrompt($retailerName, $title, $validFrom, $validUntil, $heroDeals, $discountRange);

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'topP' => 0.95, 'topK' => 40, 'responseMimeType' => 'text/plain'],
        ];

        $raw = $this->callGeminiRawText($payload, $apiKey);
        $text = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['"', '"', '"', '&quot;'], '', $text);
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
     * Editorial Overview prompt — 150 words, 2 paragraphs, fluff-free
     */
    /**
     * Raw text call without JSON schema (for BLUF).
     *
     * @param  array<string, mixed>  $payload
     */
    private function callGeminiRawText(array $payload, string $apiKey): string
    {
        $url = self::BASE_URL.'/'.$this->model().':generateContent';
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url.'?key='.$apiKey, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini BLUF error: HTTP '.$response->status());
        }

        $json = $response->json();
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini BLUF empty response');
        }

        return trim($text);
    }

    /**
     * Classification prompt with Egyptian retail domain knowledge — hard-negative
     * constraints (installment/app/single-product ads), Flash 1-Day aware, teaser-proof, no date invention.
     */
    private function classificationPrompt(int $imageCount = 1): string
    {
        $today = now('Africa/Cairo')->format('Y-m-d');

        return <<<PROMPT
You are the STRICT Gatekeeper for عروض نت (Egyptian retail flyer aggregator).

Current date (Africa/Cairo): {$today}. Assume year 2026 if year not explicitly visible in post/image.
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
  * FORMULA A (Single-Day / Flash): "عروض [اسم المتجر] [اسم اليوم بالعربية] [رقم اليوم] [اسم الشهر بالعربية] [السنة] | عرض اليوم الواحد" — e.g. "عروض كازيون الأربعاء 9 سبتمبر 2026 | عرض اليوم الواحد"
  * FORMULA B (Multi-Day): "عروض [اسم المتجر] من [X] حتى [Y] [اسم الشهر بالعربية] [السنة] | [المناسبة]" — e.g. "عروض كارفور من 10 حتى 20 سبتمبر 2026 | مجلة العروض والتوفير"
- valid_from / valid_until = Extract ONLY explicit, verifiable dates stated in caption or image. Normalize to YYYY-MM-DD (year 2026 if year not visible; today is {$today}). NEVER invent, estimate, or hallucinate dates. If unsure, or if the post is an installment/app/single-product promo, return is_flyer = false.
- EGYPTIAN RETAIL TEMPORAL PARSING — MUST explicitly support all 4 core structures:
  1. Single-Day Flash Deals ("يوم السبت 12 سبتمبر فقط"): valid_from = valid_until = that exact day.
  2. Two-Day Weekend Offers ("يوم الجمعة 11 سبتمبر ويوم السبت 12 سبتمبر"): valid_from="2026-09-11", valid_until="2026-09-12".
  3. Cross-Month Campaigns ("من 11 سبتمبر حتى 1 أكتوبر"): increment the month for end dates.
  4. Open-ended with Start Date ("ساري من 10 سبتمبر وحتى نفاذ الكمية"): Calculate valid_until as valid_from + 10 days.
     EXCEPTION — "Until Stock Lasts" (حتى نفاذ الكمية): if the post contains a clear explicit START date (valid_from) AND the phrase "حتى نفاذ الكمية" (variants: "حتى نفاد الكمية", "حتي نفاذ الكميه", "until stock lasts"), you MAY accept it as a valid flyer (is_flyer = true) — BUT ONLY if it ALSO passes the REAL FLYER multi-product catalog test above; brand promos and single-product ads remain rejected (is_flyer = false). If BOTH valid_from and valid_until are missing/cannot be extracted, you MUST reject — no exception applies.
=== EGYPTIAN SLANG & COLLOQUIAL DATE RULES ===
- "إنهاردة" / "النهارده" means "Today" ({$today}); "بكرة" / "بكره" means "Tomorrow".
- If text says "من إنهاردة وحتى 16-9-2026", valid_from = "{$today}", valid_until = "2026-09-16".
- If text says "إنهاردة وبكرة الإثنين والثلاثاء 7 و 8 سبتمبر", valid_from = "2026-09-07", valid_until = "2026-09-08".
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
- product_name: Exact Arabic name as printed (include size/weight if shown, e.g., "أرز مصري 5 كجم").
- sale_price: float (EGP) after applying superscript rule.
- old_price: nullable float (same rule) if crossed-out price exists.
- unit: nullable string normalized: one of "كجم","جرام","لتر","مل","قطعة","علبة","كرتونة","حبة","كيس" or null if not stated. Infer from packaging text (e.g., "1 لتر", "500 جم").
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
                        'required' => ['product_name', 'sale_price', 'old_price', 'unit', 'brand_name', 'bundle_condition', 'extra_attributes'],
                        'propertyOrdering' => ['product_name', 'sale_price', 'old_price', 'unit', 'brand_name', 'bundle_condition', 'extra_attributes'],
                    ],
                ],
            ],
            'required' => ['items'],
            'propertyOrdering' => ['items'],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{product_name: string, sale_price: float, old_price: ?float, unit: ?string, brand_name: ?string, bundle_condition: ?string, extra_attributes: array<string, string>}>
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
````

---

## 4) app/Services/CloudflareCacheService.php

````php
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
````

---

## 5) app/Services/ImageOptimizerService.php

````php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Spatie\PdfToImage\Pdf;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

final class ImageOptimizerService
{
    private const MAX_WIDTH = 1200;

    private const WEBP_QUALITY = 80;

    private const DOWNLOAD_TIMEOUT = 15;

    /**
     * Resolve the best available image manager (Imagick preferred, GD fallback).
     */
    private function imageManager(): ImageManager
    {
        try {
            if (extension_loaded('imagick')) {
                return new ImageManager(new ImagickDriver);
            }
        } catch (Throwable $e) {
            Log::warning('Imagick driver unavailable, falling back to GD.', [
                'error' => $e->getMessage(),
            ]);
        }

        return new ImageManager(new GdDriver);
    }

    /**
     * Check if URL points to a PDF (case-insensitive, ignores query string).
     */
    private function isPdfUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return str_ends_with(strtolower($path), '.pdf');
    }

    /**
     * Download raw binary with defensive timeout.
     *
     * @throws RuntimeException
     */
    private function downloadBinary(string $url): string
    {
        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT)
                ->withHeaders([
                    'User-Agent' => 'AmanPrice-ImageOptimizer/1.0 (+https://amanprice.tech)',
                    'Accept' => '*/*',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::error('Image download failed: non-successful status.', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body_snippet' => substr((string) $response->body(), 0, 500),
                ]);

                throw new RuntimeException('Failed to download resource: HTTP '.$response->status().' for URL '.$url);
            }

            $body = $response->body();

            if ($body === '' || $body === '0') {
                // Allow '0' as valid but usually empty is failure
                if ($body === '') {
                    throw new RuntimeException('Downloaded resource is empty for URL '.$url);
                }
            }

            return $body;
        } catch (RequestException $e) {
            Log::error('HTTP request exception during download.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to download resource (request exception): '.$e->getMessage(), 0, $e);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Unexpected error during download.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to download resource: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Normalize directory to avoid double slashes or traversal.
     */
    private function normalizeDirectory(string $directory): string
    {
        $dir = trim($directory);
        $dir = trim($dir, '/');
        $dir = str_replace(['..', '\\'], '', $dir);
        $dir = (string) preg_replace('#/+#', '/', $dir);

        return trim($dir, '/');
    }

    /**
     * Public wrapper for optimizing binary (used by observers and Filament uploads).
     *
     * @throws RuntimeException
     */
    public function convertToWebp(string $binary): string
    {
        return $this->optimizeImageBinary($binary);
    }

    /**
     * Store WebP binary to any disk with immutable cache headers (if R2).
     *
     * @throws RuntimeException
     */
    public function storeWebp(string $webpBinary, string $directory, string $disk = 'r2'): string
    {
        try {
            $normalizedDir = $this->normalizeDirectory($directory);
            $filename = Str::ulid()->toString().'.webp';
            $key = $normalizedDir !== '' ? $normalizedDir.'/'.$filename : $filename;

            $options = [
                'visibility' => 'public',
                'ContentType' => 'image/webp',
            ];

            // Only R2 needs CacheControl immutable; public disk ignores it
            if ($disk === 'r2') {
                $options['CacheControl'] = 'public, max-age=31536000, immutable';
            }

            Storage::disk($disk)->put($key, $webpBinary, $options);

            Log::info('WebP stored.', ['disk' => $disk, 'key' => $key, 'bytes' => strlen($webpBinary)]);

            return $key;
        } catch (Throwable $e) {
            Log::error('WebP store failed.', ['disk' => $disk, 'directory' => $directory, 'error' => $e->getMessage()]);

            throw new RuntimeException('Failed to store WebP: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Optimize an UploadedFile (from Filament) and store as WebP.
     *
     * @throws RuntimeException
     */
    public function processUploadedFile(UploadedFile $file, string $directory, string $disk = 'r2'): string
    {
        $binary = (string) file_get_contents($file->getRealPath());

        if ($binary === '') {
            throw new RuntimeException('Uploaded file is empty: '.$file->getClientOriginalName());
        }

        $webpBinary = $this->convertToWebp($binary);

        return $this->storeWebp($webpBinary, $directory, $disk);
    }

    /**
     * Process a raw image binary: downscale if width > 1200, encode WebP q80.
     *
     * @throws RuntimeException
     */
    private function optimizeImageBinary(string $binary): string
    {
        try {
            $manager = $this->imageManager();
            $image = $manager->read($binary);

            // Detect dimensions and downscale proportionally if needed
            try {
                $width = $image->width();
                if ($width > self::MAX_WIDTH) {
                    // scaleDown ensures we only shrink, never upscale, preserving aspect ratio
                    $image->scaleDown(width: self::MAX_WIDTH);
                }
            } catch (Throwable $e) {
                Log::warning('Could not determine image width for scaling.', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Convert to WebP with quality 80 - preserves fine print legibility
            try {
                $encoded = $image->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
            } catch (Throwable $e) {
                Log::error('WebP encoding failed.', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Failed to encode image to WebP: '.$e->getMessage(), 0, $e);
            }

            $webpBinary = (string) $encoded;

            if ($webpBinary === '') {
                throw new RuntimeException('WebP encoding produced empty binary.');
            }

            // Optional size guard: log if over 150KB (spec target), but do not fail
            if (strlen($webpBinary) > 150 * 1024) {
                Log::warning('Optimized WebP exceeds 150KB target.', [
                    'size_bytes' => strlen($webpBinary),
                ]);
            }

            return $webpBinary;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Image optimization failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('Image optimization failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Upload WebP binary to R2 with immutable cache headers.
     *
     * @throws RuntimeException
     */
    private function uploadToR2(string $binary, string $directory): string
    {
        return $this->storeWebp($binary, $directory, 'r2');
    }

    /**
     * Process and upload a remote image to R2 as optimized WebP.
     *
     * - Downloads with 15s timeout, throws RuntimeException if unreachable
     * - Downscales width >1200 proportionally
     * - Converts to WebP quality 80
     * - ULID filename, uploads to `r2` with immutable cache
     *
     * @throws RuntimeException
     */
    public function processAndUpload(string $rawSourceUrl, string $directory): string
    {
        try {
            if (trim($rawSourceUrl) === '') {
                throw new RuntimeException('Source URL is empty.');
            }

            // Fallback: PDF detection at entry point
            if ($this->isPdfUrl($rawSourceUrl)) {
                Log::info('Source URL is PDF, delegating to PDF handler.', [
                    'url' => $rawSourceUrl,
                ]);

                $keys = $this->processPdfAndUpload($rawSourceUrl, $directory);

                if ($keys === []) {
                    throw new RuntimeException('PDF processing produced no images for URL '.$rawSourceUrl);
                }

                // For backward compatibility, return first page key
                // Caller that needs all pages should use processPdfAndUpload directly
                if (count($keys) > 1) {
                    Log::warning('PDF produced multiple pages, returning first key via processAndUpload.', [
                        'url' => $rawSourceUrl,
                        'count' => count($keys),
                    ]);
                }

                return $keys[0];
            }

            $binary = $this->downloadBinary($rawSourceUrl);
            $webpBinary = $this->optimizeImageBinary($binary);

            return $this->uploadToR2($webpBinary, $directory);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('processAndUpload failed.', [
                'url' => $rawSourceUrl,
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('processAndUpload failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Fallback for PDF sources: downloads PDF and converts each page to WebP.
     *
     * Tries `spatie/pdf-to-image` (requires imagick + ghostscript) first,
     * then falls back to native `pdftoppm` (poppler-utils) shell execution.
     *
     * @return array<int, string> List of R2 keys, one per PDF page
     *
     * @throws RuntimeException
     */
    public function processPdfAndUpload(string $rawSourceUrl, string $directory): array
    {
        $tmpPdf = null;
        $tempImages = [];

        try {
            $binary = $this->downloadBinary($rawSourceUrl);

            // Validate PDF magic bytes
            if (! str_starts_with(ltrim($binary), '%PDF')) {
                Log::warning('Downloaded PDF does not start with %PDF header, proceeding anyway.', [
                    'url' => $rawSourceUrl,
                ]);
            }

            // Write PDF to temp file
            $tmpPdf = tempnam(sys_get_temp_dir(), 'aman_pdf_');
            if ($tmpPdf === false) {
                throw new RuntimeException('Failed to create temp file for PDF.');
            }
            $tmpPdf .= '.pdf';
            $written = file_put_contents($tmpPdf, $binary);
            if ($written === false) {
                throw new RuntimeException('Failed to write PDF to temp file: '.$tmpPdf);
            }

            // Strategy 1: spatie/pdf-to-image if available
            if (class_exists(Pdf::class)) {
                try {
                    return $this->processPdfViaSpatie($tmpPdf, $directory, $tempImages);
                } catch (Throwable $e) {
                    Log::warning('Spatie PDF conversion failed, trying pdftoppm fallback.', [
                        'url' => $rawSourceUrl,
                        'error' => $e->getMessage(),
                    ]);
                    // Fall through to pdftoppm
                }
            }

            // Strategy 2: native pdftoppm
            if ($this->isPdftoppmAvailable()) {
                try {
                    return $this->processPdfViaPdftoppm($tmpPdf, $directory, $tempImages);
                } catch (Throwable $e) {
                    Log::error('pdftoppm conversion failed.', [
                        'url' => $rawSourceUrl,
                        'error' => $e->getMessage(),
                    ]);

                    throw new RuntimeException('PDF conversion via pdftoppm failed: '.$e->getMessage(), 0, $e);
                }
            }

            throw new RuntimeException('No PDF conversion backend available (spatie/pdf-to-image missing and pdftoppm not found). Install ext-imagick+ghostscript or poppler-utils.');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('processPdfAndUpload failed.', [
                'url' => $rawSourceUrl,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('PDF processing failed: '.$e->getMessage(), 0, $e);
        } finally {
            // Cleanup temp PDF
            if (is_string($tmpPdf) && file_exists($tmpPdf)) {
                @unlink($tmpPdf);
            }
            // Cleanup any leftover temp images
            foreach ($tempImages as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Convert PDF via spatie/pdf-to-image (each page -> WebP).
     *
     * @param  array<int, string>  $tempImagesCollector  Collects temp paths for cleanup
     * @return array<int, string>
     *
     * @throws Throwable
     */
    private function processPdfViaSpatie(string $pdfPath, string $directory, array &$tempImagesCollector): array
    {
        $pdf = new Pdf($pdfPath);
        $pdf->setResolution(150);

        $numPages = $pdf->getNumberOfPages();
        if ($numPages < 1) {
            throw new RuntimeException('PDF has no pages: '.$pdfPath);
        }

        Log::info('Converting PDF via spatie/pdf-to-image.', [
            'path' => $pdfPath,
            'pages' => $numPages,
        ]);

        $keys = [];

        for ($page = 1; $page <= $numPages; $page++) {
            $tmpImage = sys_get_temp_dir().'/'.Str::ulid()->toString().'-page-'.$page.'.jpg';
            $tempImagesCollector[] = $tmpImage;

            try {
                // Spatie saves page as image; we request JPG for broad compat
                $pdf->setPage($page)->saveImage($tmpImage);

                if (! file_exists($tmpImage)) {
                    throw new RuntimeException('Spatie failed to generate image for page '.$page);
                }

                $binary = (string) file_get_contents($tmpImage);
                if ($binary === '') {
                    throw new RuntimeException('Generated image is empty for page '.$page);
                }

                $webpBinary = $this->optimizeImageBinary($binary);
                $keys[] = $this->uploadToR2($webpBinary, $directory);
            } catch (Throwable $e) {
                Log::error('Failed to process PDF page via Spatie.', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            } finally {
                if (file_exists($tmpImage)) {
                    @unlink($tmpImage);
                    // remove from collector to avoid double unlink
                    $tempImagesCollector = array_filter($tempImagesCollector, fn (string $p): bool => $p !== $tmpImage);
                }
            }
        }

        return $keys;
    }

    /**
     * Check if pdftoppm is available on the system.
     */
    private function isPdftoppmAvailable(): bool
    {
        try {
            $process = new Process(['which', 'pdftoppm']);
            // Windows compatibility: `where` on win32, `which` on unix
            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process(['where', 'pdftoppm']);
            }
            $process->run();
            if ($process->isSuccessful() && trim((string) $process->getOutput()) !== '') {
                return true;
            }

            // Fallback: try `pdftoppm -v` directly
            $probe = new Process(['pdftoppm', '-v']);
            $probe->run();
            if (! $probe->isSuccessful()) {
                return false;
            }
            $output = $probe->getErrorOutput().$probe->getOutput();

            return str_contains(strtolower($output), 'version') || str_contains($output, 'pdftoppm');
        } catch (Throwable $e) {
            Log::warning('pdftoppm availability check failed.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convert PDF via native pdftoppm (poppler-utils).
     *
     * @param  array<int, string>  $tempImagesCollector
     * @return array<int, string>
     *
     * @throws Throwable
     */
    private function processPdfViaPdftoppm(string $pdfPath, string $directory, array &$tempImagesCollector): array
    {
        Log::info('Converting PDF via pdftoppm.', [
            'path' => $pdfPath,
        ]);

        $outputPrefix = sys_get_temp_dir().'/'.Str::ulid()->toString().'-pdfpage';

        // Render all pages at 150 DPI to PNG (preserves clarity for price fine print)
        $process = new Process([
            'pdftoppm',
            '-png',
            '-r', '150',
            $pdfPath,
            $outputPrefix,
        ]);
        $process->setTimeout(60);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            Log::error('pdftoppm process failed.', [
                'command' => $process->getCommandLine(),
                'error_output' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('pdftoppm failed: '.$process->getErrorOutput(), 0, $e);
        }

        $pattern = $outputPrefix.'-*.png';
        $generated = glob($pattern);
        if ($generated === false || $generated === []) {
            // Some versions produce without dash: prefix-1.png vs prefix-01.png
            $generated = glob($outputPrefix.'*.png');
        }

        if ($generated === false || $generated === []) {
            throw new RuntimeException('pdftoppm produced no images for PDF: '.$pdfPath);
        }

        sort($generated, SORT_NATURAL);
        $tempImagesCollector = array_merge($tempImagesCollector, $generated);

        $keys = [];

        foreach ($generated as $pngPath) {
            try {
                $binary = (string) file_get_contents($pngPath);
                if ($binary === '') {
                    Log::warning('Skipping empty pdftoppm page.', ['path' => $pngPath]);

                    continue;
                }

                $webpBinary = $this->optimizeImageBinary($binary);
                $keys[] = $this->uploadToR2($webpBinary, $directory);
            } catch (Throwable $e) {
                Log::error('Failed to process pdftoppm page.', [
                    'path' => $pngPath,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            } finally {
                if (file_exists($pngPath)) {
                    @unlink($pngPath);
                }
            }
        }

        // Remove cleaned paths from collector
        $tempImagesCollector = array_filter(
            $tempImagesCollector,
            fn (string $p): bool => ! in_array($p, $generated, true) || file_exists($p)
        );

        if ($keys === []) {
            throw new RuntimeException('No pages were successfully converted from PDF: '.$pdfPath);
        }

        return $keys;
    }
}
````

---

## 6) app/Filament/Resources/FlyerResource.php

````php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FlyerStatus;
use App\Filament\Resources\FlyerResource\Pages;
use App\Filament\Resources\FlyerResource\RelationManagers\ItemsRelationManager;
use App\Jobs\PingIndexNowJob;
use App\Models\Flyer;
use App\Services\ImageOptimizerService;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class FlyerResource extends Resource
{
    protected static ?string $model = Flyer::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Header')
                    ->description('Core flyer metadata and publication window.')
                    ->schema([
                        Forms\Components\Select::make('retailer_id')
                            ->label('Retailer')
                            ->relationship('retailer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Forms\Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('معرف الرابط (SEO Slug)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('يتم إنشاؤه إنجليزياً وتلقائياً بحسب المتجر والتواريخ وهو ثابت دائماً لحماية الأرشفة.')
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('valid_from')
                            ->label('Valid From')
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d'),

                        Forms\Components\DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->rule('after_or_equal:valid_from', true),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                FlyerStatus::Draft->value => 'Draft',
                                FlyerStatus::PendingReview->value => 'Pending Review',
                                FlyerStatus::Published->value => 'Published',
                                FlyerStatus::Expired->value => 'Expired',
                            ])
                            ->enum(FlyerStatus::class)
                            ->required()
                            ->default(FlyerStatus::Draft->value)
                            ->native(false),

                        Forms\Components\Textarea::make('bluf_summary')
                            ->label('BLUF Summary (SEO/GEO)')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('2-sentence Bottom Line Up Front for search and GEO.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('editorial_overview')
                            ->label('نص المقال التحريري ونقاط التوفير (مستخرج بالذكاء الاصطناعي - قابل للتعديل بالكامل)')
                            ->rows(8)
                            ->helperText('يمكنك تعديل أي نقطة أو رقم أو إضافة ملاحظات قبل النشر.')
                            ->columnSpanFull()
                            ->placeholder('سيتم توليده تلقائياً عبر Gemini بصيغة نقطية منظمة، يمكنك تعديله هنا...'),

                        Forms\Components\Select::make('applicable_governorates')
                            ->label('Applicable Governorates')
                            ->multiple()
                            ->options(self::governorateOptions())
                            ->searchable()
                            ->placeholder('All governorates (nationwide)')
                            ->helperText('Leave empty for nationwide.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('total_pages')
                            ->label('Total Pages')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pages')
                    ->description('Facebook image order — use ↑ ↓ buttons to reorder safely. Thumbnails from R2.')
                    ->schema([
                        Repeater::make('pages')
                            ->label('Flyer Pages')
                            ->relationship('pages')
                            // orderColumn stays INTENTIONALLY: it is the single numbering
                            // authority (dense 1..N by position, incl. new rows). It is safe
                            // ONLY because the mutators below pre-lift every row into a
                            // temp zone first — never use orderColumn on a
                            // UNIQUE(flyer_id, page_number) index without that guard.
                            ->orderColumn('page_number')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => isset($state['page_number']) ? 'Page '.$state['page_number'] : 'New Page')
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data, Repeater $component): array {
                                // Phase 1 of the two-phase save (idempotent): lift rows out
                                // of the way so the exact page_number writes below collide
                                // with nothing. Runs before EVERY item write.
                                self::offsetFlyerPagesForFormSave($component);

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, Repeater $component): array {
                                // Same guard before inserts: a mid-list insert's number is
                                // always free once existing rows are lifted.
                                self::offsetFlyerPagesForFormSave($component);

                                return $data;
                            })
                            ->extraItemActions([
                                Action::make('movePageUp')
                                    ->label('Move page up')
                                    ->icon('heroicon-m-chevron-up')
                                    ->iconButton()
                                    ->action(function (array $arguments, Repeater $component): void {
                                        self::moveFlyerPage($component, (string) ($arguments['item'] ?? ''), -1);
                                    }),
                                Action::make('movePageDown')
                                    ->label('Move page down')
                                    ->icon('heroicon-m-chevron-down')
                                    ->iconButton()
                                    ->action(function (array $arguments, Repeater $component): void {
                                        self::moveFlyerPage($component, (string) ($arguments['item'] ?? ''), 1);
                                    }),
                            ])
                            ->schema([
                                Forms\Components\FileUpload::make('image_path')
                                    ->label('Page Image')
                                    ->disk(self::storageDisk())
                                    ->directory('flyers/pages')
                                    ->visibility('public')
                                    ->image()
                                    ->openable()
                                    ->downloadable()
                                    ->imagePreviewHeight('250')
                                    ->maxSize(5120)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jpg'])
                                    ->required()
                                    ->dehydrated(true)
                                    ->helperText(fn (): string => self::isR2Configured() ? 'Stored on R2 — سيتم ضغطه تلقائياً إلى WebP 1200px جودة 80.' : 'R2 not configured — stored locally (public/flyers/pages) كـ WebP.')
                                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                        $disk = self::storageDisk();
                                        /** @var ImageOptimizerService $optimizer */
                                        $optimizer = app(ImageOptimizerService::class);
                                        try {
                                            return $optimizer->processUploadedFile($file, 'flyers/pages', $disk);
                                        } catch (\Throwable $e) {
                                            Log::warning('WebP conversion failed, storing original.', ['error' => $e->getMessage()]);
                                            // Fallback: store original as-is with canonical directory
                                            $path = $file->store('flyers/pages', $disk);

                                            $resolved = is_string($path) ? $path : 'flyers/pages/'.$file->getClientOriginalName();

                                            // Normalize duplicate prefix if any
                                            $resolved = ltrim($resolved, '/');
                                            $resolved = (string) preg_replace('#^flyers/pages/flyers/pages/#', 'flyers/pages/', $resolved);

                                            return $resolved;
                                        }
                                    }),

                                Forms\Components\Hidden::make('page_number')
                                    ->default(1),

                                Forms\Components\TextInput::make('width')
                                    ->label('Width')
                                    ->numeric()
                                    ->default(1200)
                                    ->required(),

                                Forms\Components\TextInput::make('height')
                                    ->label('Height')
                                    ->numeric()
                                    ->default(1600)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add Page'),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('retailer.name')
                    ->label('Retailer')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->title),

                Tables\Columns\TextColumn::make('date_range')
                    ->label('Valid Range')
                    ->state(fn (Flyer $record): string => $record->valid_from?->format('Y-m-d').' → '.$record->valid_until?->format('Y-m-d'))
                    ->searchable(false)
                    ->sortable(false)
                    ->icon('heroicon-m-calendar')
                    ->copyable(false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (FlyerStatus $state): string => match ($state) {
                        FlyerStatus::Draft => 'gray',
                        FlyerStatus::PendingReview => 'warning',
                        FlyerStatus::Published => 'success',
                        FlyerStatus::Expired => 'danger',
                    })
                    ->icon(fn (FlyerStatus $state): ?string => match ($state) {
                        FlyerStatus::Draft => 'heroicon-o-pencil-square',
                        FlyerStatus::PendingReview => 'heroicon-o-clock',
                        FlyerStatus::Published => 'heroicon-o-check-circle',
                        FlyerStatus::Expired => 'heroicon-o-x-circle',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('pages_count')
                    ->label('Pages')
                    ->counts('pages')
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('valid_from')
                    ->label('From')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Until')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('retailer_id')
                    ->label('Retailer')
                    ->relationship('retailer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        FlyerStatus::Draft->value => 'Draft',
                        FlyerStatus::PendingReview->value => 'Pending Review',
                        FlyerStatus::Published->value => 'Published',
                        FlyerStatus::Expired->value => 'Expired',
                    ]),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->where('valid_from', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->where('valid_until', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('publishAndIndex')
                    ->label('Publish & Index')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Publish flyer & ping IndexNow?')
                    ->modalDescription('This will set status to published and queue an IndexNow ping in background.')
                    ->visible(fn (Flyer $record): bool => $record->status !== FlyerStatus::Published)
                    ->action(function (Flyer $record): void {
                        $record->status = FlyerStatus::Published;
                        $record->save();

                        Notification::make()
                            ->title('Flyer published')
                            ->body('"'.$record->title.'" is now published and queued for IndexNow.')
                            ->success()
                            ->send();

                        // Canonical host from config — route() here would embed
                        // the Filament/admin request host into IndexNow.
                        PingIndexNowJob::dispatch(
                            rtrim((string) config('app.url'), '/').'/offers/'.rawurlencode((string) $record->slug),
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('bulkPublish')
                        ->label('Publish selected')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->status = FlyerStatus::Published;
                                $record->save();
                                // Canonical host from config — never the admin host.
                                PingIndexNowJob::dispatch(
                                    rtrim((string) config('app.url'), '/').'/offers/'.rawurlencode((string) $record->slug),
                                );
                            }

                            Notification::make()
                                ->title('Bulk publish queued')
                                ->body(count($records).' flyers published and IndexNow pings queued.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('valid_from', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlyers::route('/'),
            'create' => Pages\CreateFlyer::route('/create'),
            'edit' => Pages\EditFlyer::route('/{record}/edit'),
            'view' => Pages\ViewFlyer::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['retailer'])->withCount(['items', 'pages']);
    }

    /**
     * @return array<string, string>
     */
    private static function governorateOptions(): array
    {
        return [
            'القاهرة' => 'القاهرة (Cairo)',
            'الجيزة' => 'الجيزة (Giza)',
            'الإسكندرية' => 'الإسكندرية (Alexandria)',
            'الدقهلية' => 'الدقهلية (Dakahlia)',
            'الشرقية' => 'الشرقية (Sharqia)',
            'المنوفية' => 'المنوفية (Menoufia)',
            'القليوبية' => 'القليوبية (Qalyubia)',
            'البحيرة' => 'البحيرة (Beheira)',
            'كفر الشيخ' => 'كفر الشيخ (Kafr El Sheikh)',
            'الغربية' => 'الغربية (Gharbia)',
            'المنيا' => 'المنيا (Minya)',
            'أسيوط' => 'أسيوط (Asyut)',
            'سوهاج' => 'سوهاج (Sohag)',
            'قنا' => 'قنا (Qena)',
            'أسوان' => 'أسوان (Aswan)',
            'الأقصر' => 'الأقصر (Luxor)',
            'البحر الأحمر' => 'البحر الأحمر (Red Sea)',
            'بورسعيد' => 'بورسعيد (Port Said)',
            'السويس' => 'السويس (Suez)',
            'الإسماعيلية' => 'الإسماعيلية (Ismailia)',
            'دمياط' => 'دمياط (Damietta)',
            'مطروح' => 'مطروح (Matrouh)',
            'الوادي الجديد' => 'الوادي الجديد (New Valley)',
            'شمال سيناء' => 'شمال سيناء (North Sinai)',
            'جنوب سيناء' => 'جنوب سيناء (South Sinai)',
            'بني سويف' => 'بني سويف (Beni Suef)',
            'الفيوم' => 'الفيوم (Fayoum)',
        ];
    }

    private static function isR2Configured(): bool
    {
        return filled(config('filesystems.disks.r2.bucket')) && filled(config('filesystems.disks.r2.endpoint'));
    }

    private static function storageDisk(): string
    {
        return self::isR2Configured() ? 'r2' : 'public';
    }

    /**
     * Phase 1 of the two-phase form save: lift every page row of the edited
     * flyer into a temp zone (>= 100000) so subsequent exact page_number
     * writes can never collide with UNIQUE(flyer_id, page_number).
     * Idempotent per save (only rows below the temp zone move) and a no-op
     * for not-yet-created flyers. Query-builder: fires zero model events.
     */
    private static function offsetFlyerPagesForFormSave(Repeater $component): void
    {
        $flyerId = $component->getRecord()?->getKey();
        if (! $flyerId) {
            return;
        }

        DB::table('flyer_pages')
            ->where('flyer_id', $flyerId)
            ->where('page_number', '<', 100000)
            ->update(['page_number' => DB::raw('page_number + 100000'), 'updated_at' => now()]);
    }

    /**
     * Move a flyer page up/down by one position — PURE Livewire state, zero
     * database writes (form actions must never mutate the DB pre-submit).
     *
     * Swaps positions and mirrors page_number values so labels stay accurate;
     * orderColumn() + the two-phase save guard persist the final order
     * collision-free on submit. Unsaved edits elsewhere are preserved.
     */
    private static function moveFlyerPage(Repeater $component, string $uuid, int $direction): void
    {
        $state = $component->getState();
        if (! is_array($state) || ! array_key_exists($uuid, $state)) {
            return;
        }

        $keys = array_keys($state);
        $pos = array_search($uuid, $keys, true);
        if ($pos === false || ! isset($keys[$pos + $direction])) {
            return;
        }
        $otherUuid = $keys[$pos + $direction];

        $rowA = $state[$uuid];
        $rowB = $state[$otherUuid];
        $tmp = $rowA['page_number'] ?? null;
        $rowA['page_number'] = $rowB['page_number'] ?? null;
        $rowB['page_number'] = $tmp;

        $orderedKeys = $keys;
        [$orderedKeys[$pos], $orderedKeys[$pos + $direction]] = [$orderedKeys[$pos + $direction], $orderedKeys[$pos]];

        $newState = [];
        foreach ($orderedKeys as $key) {
            $newState[$key] = $key === $uuid ? $rowA : ($key === $otherUuid ? $rowB : $state[$key]);
        }

        $component->state($newState);
    }
}
````

---

## 7) resources/views/flyers/show.blade.php

````blade
@php
    use App\Support\R2Url;
    $r2DiskUrl = R2Url::base();
    $firstPage = $flyer->pages->first();
    $coverImage = $firstPage ? R2Url::asset($firstPage->image_path) : url('/img/og-cover.png');
    $pageUrl = route('flyers.show', $flyer->slug);
    $cleanTitle = $flyer->title;

    // BLUF — Bulletproof Arabic via Flyer accessor
    $bluf = $flyer->bluf_summary;
@endphp

<x-layouts.app
    :meta-title="$cleanTitle"
    :meta-description="$bluf"
    :og-title="$cleanTitle"
    :og-description="$bluf"
    :og-image="$coverImage"
    og-type="article"
>
    @push('schema')
        @php
            $siteName = config('app.name', 'مجلاتنا');
            $organizationId = url('/') . '#organization';
            $websiteId = url('/') . '#website';
            $breadcrumbId = $pageUrl . '#breadcrumb';
            $eventId = $pageUrl . '#event';
            $itemListId = $pageUrl . '#itemlist';

            // Page-id => absolute image URL map (from already-loaded pages: zero extra queries).
            // Lets each Product carry its own page image instead of the shared cover.
            $pageImages = $flyer->pages->mapWithKeys(fn ($p) => [$p->id => R2Url::asset($p->image_path)]);

            $graph = [
                [
                    '@type' => 'Organization',
                    '@id' => $organizationId,
                    'name' => $siteName,
                    'url' => url('/'),
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => url('/favicon.svg'),
                        'width' => 140,
                        'height' => 36,
                    ],
                    'areaServed' => [
                        '@type' => 'Country',
                        'name' => 'EG',
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => url('/'),
                    'name' => $siteName,
                    'publisher' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $flyer->retailer->name, 'item' => route('retailers.show', $flyer->retailer->slug)],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $cleanTitle, 'item' => $pageUrl],
                    ],
                ],
                [
                    '@type' => 'SaleEvent',
                    '@id' => $eventId,
                    'name' => $cleanTitle,
                    'description' => $bluf,
                    'startDate' => \App\Support\CairoTime::toIso8601(\Carbon\Carbon::parse($flyer->valid_from)->startOfDay()),
                    'endDate' => \App\Support\CairoTime::toIso8601(\Carbon\Carbon::parse($flyer->valid_until)->endOfDay()),
                    'eventStatus' => $flyer->isExpired() ? 'EventCancelled' : 'EventScheduled',
                    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                    'location' => [
                        '@type' => 'Place',
                        'name' => 'فروع ' . $flyer->retailer->name . ' بمصر',
                        'address' => [
                            '@type' => 'PostalAddress',
                            'addressCountry' => 'EG',
                        ],
                    ],
                    'organizer' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'ItemList',
                    '@id' => $itemListId,
                    'name' => 'قائمة أسعار وسلع ' . $cleanTitle,
                    'numberOfItems' => $flyer->items->count(),
                    'itemListElement' => $flyer->items->take(50)->values()->map(function ($item, $idx) use ($pageUrl, $flyer, $coverImage, $pageImages) {
                        $hasOld = $item->old_price && (float) $item->old_price > (float) $item->sale_price;
                        $offer = [
                            '@type' => 'Offer',
                            'url' => $pageUrl . '#item-' . $item->id,
                            'price' => number_format((float) $item->sale_price, 2, '.', ''),
                            'priceCurrency' => 'EGP',
                            'priceValidUntil' => \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d'),
                            'availability' => $flyer->isExpired() ? 'https://schema.org/Discontinued' : 'https://schema.org/InStock',
                            'seller' => ['@type' => 'Organization', 'name' => $flyer->retailer->name],
                        ];
                        if ($hasOld) {
                            $offer['priceSpecification'] = [
                                '@type' => 'UnitPriceSpecification',
                                'priceType' => 'https://schema.org/ListPrice',
                                'price' => number_format((float) $item->old_price, 2, '.', ''),
                                'priceCurrency' => 'EGP',
                            ];
                        }
                        return [
                            '@type' => 'ListItem',
                            'position' => $idx + 1,
                            'item' => [
                                '@type' => 'Product',
                                'name' => $item->product_name,
                                'image' => $pageImages[$item->flyer_page_id] ?? $coverImage,
                                'url' => $pageUrl . '#item-' . $item->id,
                                'brand' => ['@type' => 'Brand', 'name' => $item->brand?->name ?: $item->product_name],
                                'offers' => $offer,
                            ],
                        ];
                    })->all(),
                ],
            ];

            $unifiedSchema = [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($unifiedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @endpush

    <!-- Breadcrumb - Navy hover -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="/" class="hover:text-[#039652]">الرئيسية</a></li>
            <li>/</li>
            <li><a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="hover:text-[#039652]">{{ $flyer->retailer->name }}</a></li>
            <li>/</li>
            <li class="text-[#023b55] truncate max-w-xs font-bold">{{ $cleanTitle }}</li>
        </ol>
    </nav>

    <!-- Flyer Header Card - Navy headings, Green validity -->
    <article class="mb-8 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        @if($flyer->isExpired())
            <div class="mb-6 rounded-2xl border-2 border-amber-500/30 bg-amber-50/80 p-5 text-amber-950 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white font-black text-lg">⚠️</span>
                    <div class="flex-1">
                        <h2 class="text-base font-black text-amber-900">تنبيه: هذا العرض انتهت فترة سريانه في {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y/m/d') }}</h2>
                        <p class="mt-0.5 text-xs text-amber-800">
                            الأسعار والخصومات الواردة أدناه محفوظة كأرشيف تاريخي لمقارنة تطور الأسعار في مصر.
                        </p>
                        @if($latestActive = $flyer->retailer->latestActiveFlyer())
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 border border-amber-200">
                                <span class="text-xs font-bold text-slate-700">تتوفر الآن مجلة جديدة سارية لهذا المتجر:</span>
                                <a href="{{ route('flyers.show', $latestActive->slug) }}" class="inline-flex items-center gap-1 rounded-lg bg-[#039652] px-3.5 py-1.5 text-xs font-black text-white hover:bg-[#023b55] transition shadow-sm">
                                    مشاهدة عروض {{ $flyer->retailer->name }} السارية الآن ←
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <span class="inline-block rounded-lg bg-[#023b55]/10 px-2.5 py-1 text-xs font-bold text-[#023b55]">
                    {{ $flyer->retailer->name }}
                </span>
                <h1 class="mt-2 text-2xl font-black text-[#023b55] sm:text-3xl">{{ $cleanTitle }}</h1>
            </div>

            @php
                $today = \Carbon\Carbon::today('Africa/Cairo');
                $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
            @endphp
            <div class="flex items-center gap-3">
                @if ($flyer->isExpired())
                    <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">
                        انتهى في {{ $until->format('d/m/Y') }}
                    </span>
                @elseif ($from->isFuture())
                    <span class="rounded-xl border border-[#fcc023]/50 bg-[#fcc023] px-3 py-1.5 text-xs font-bold text-slate-900">
                        يبدأ قريباً {{ $from->format('d/m/Y') }}
                    </span>
                    <span class="rounded-xl border border-[#023b55]/15 bg-white px-3 py-1.5 text-xs font-bold text-[#023b55]">
                        سارٍ من {{ $from->format('d/m/Y') }} حتى {{ $until->format('d/m/Y') }}
                    </span>
                @else
                    <span class="rounded-xl border border-[#039652]/30 bg-[#039652]/10 px-3 py-1.5 text-xs font-bold text-[#039652]">
                        سارٍ حتى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('d/m/Y') }}
                    </span>
                @endif
            </div>
        </div>

        <!-- 2-Sentence BLUF Summary Box - GEO chunk with right accent -->
        @php
            $blufHtml = e($bluf);
            // Bold critical entities for LLM RAG
            $storeName = e($flyer->retailer->name);
            $blufHtml = str_replace($storeName, '<strong>' . $storeName . '</strong>', $blufHtml);
            // Bold total pages
            $pagesText = (string) $flyer->total_pages . ' صفحة';
            $pagesTextAlt = (string) $flyer->total_pages . ' صفحات';
            $blufHtml = str_replace($pagesText, '<strong>' . $pagesText . '</strong>', $blufHtml);
            $blufHtml = str_replace($pagesTextAlt, '<strong>' . $pagesTextAlt . '</strong>', $blufHtml);
            // Bold discount percentages like 42% or 42.39%
            $blufHtml = (string) preg_replace('/(\d+(?:\.\d+)?%)/u', '<strong>$1</strong>', $blufHtml);
            // Bold date spans (dd/mm/yyyy or Arabic dates)
            $blufHtml = (string) preg_replace('/\d{1,2}\/\d{1,2}\/\d{4}/u', '<strong>$0</strong>', $blufHtml);
            // Bold prices like 14.95 ج.م
            $blufHtml = (string) preg_replace('/\d+(?:\.\d+)?\s*ج\.م/u', '<strong>$0</strong>', $blufHtml);
        @endphp
        <div class="mt-4 rounded-xl border border-slate-200 border-r-4 border-r-[#039652] bg-emerald-50/40 p-4 text-sm font-medium leading-relaxed text-slate-700">
            <p class="font-bold text-[#023b55] mb-1">📌 تفاصيل وسريان العرض:</p>
            <p>{!! $blufHtml !!}</p>
        </div>
    </article>

    <!-- عارض المجلة مصورة (Image Viewer - WebP from R2) - Desktop Optimized -->
    <section class="mb-12" id="flyer-viewer">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">تصفح صفحات المجلة ({{ $flyer->pages->count() }} صفحة)</h2>
            <span class="hidden text-xs text-slate-500 sm:inline">اضغط على أي صفحة للتكبير والتنقل</span>
        </div>

        {{-- Single focused column max-w-3xl for legibility: A4 vertical pages need full width on desktop --}}
        <div class="grid grid-cols-1 gap-4 max-w-3xl mx-auto">
            @foreach ($flyer->pages->sortBy('page_number') as $page)
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-600">
                        صفحة رقم {{ $page->page_number }}
                    </div>
                    @php
                        $fullImageUrl = R2Url::asset($page->image_path);
                        $mobileOptimizedUrl = R2Url::resizedAsset($page->image_path, 600);
                    @endphp
                    <button type="button" data-index="{{ $loop->index }}" aria-label="تكبير صفحة {{ $page->page_number }}" class="lightbox-trigger block w-full cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-[#039652] focus:ring-offset-2" style="aspect-ratio: 3/4;">
                        <img
                            src="{{ $fullImageUrl }}"
                            srcset="{{ $fullImageUrl }} 1200w, {{ $mobileOptimizedUrl }} 600w"
                            sizes="(max-width: 640px) 100vw, 768px"
                            alt="{{ $cleanTitle }} - صفحة {{ $page->page_number }}"
                            width="1200"
                            height="1600"
                            @if ($loop->first) loading="eager" fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif
                            class="w-full h-auto object-contain"
                        >
                    </button>
                </div>
            @endforeach
        </div>
    </section>

    <!-- Lightbox Modal - Pure JS, Zero CDN - Navy/Green theme -->
    <div id="flyer-lightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-[#023b55]/90 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="عارض الصفحات">
        <div class="relative flex h-full w-full max-w-5xl flex-col">
            <!-- Top Bar: Page indicator + Controls -->
            <div class="mb-3 flex items-center justify-between text-white">
                <span id="lightbox-indicator" class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur border border-white/20">صفحة 1 من 1</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="lightbox-zoom" class="rounded-full bg-white/15 p-2.5 text-white backdrop-blur transition hover:bg-[#039652] hover:text-white border border-white/20" aria-label="تكبير">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>
                    </button>
                    <button type="button" id="lightbox-close" class="rounded-full bg-white p-2.5 text-[#023b55] shadow transition hover:bg-[#039652] hover:text-white" aria-label="إغلاق">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <!-- Image Container -->
            <div id="lightbox-stage" class="relative flex flex-1 items-center justify-center overflow-hidden rounded-xl bg-[#023b55]">
                <button type="button" id="lightbox-prev" class="absolute left-2 z-10 rounded-full bg-white/90 p-3 text-[#023b55] shadow-lg transition hover:bg-[#039652] hover:text-white sm:left-4" aria-label="السابق">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <img id="lightbox-image" src="" alt="" class="max-h-[75vh] max-w-full object-contain transition-transform duration-200" style="transform: scale(1);">
                <button type="button" id="lightbox-next" class="absolute right-2 z-10 rounded-full bg-white/90 p-3 text-[#023b55] shadow-lg transition hover:bg-[#039652] hover:text-white sm:right-4" aria-label="التالي">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <p class="mt-3 text-center text-xs text-white/70">استخدم ← → للتنقل، +/- للتكبير، Esc للإغلاق</p>
        </div>
    </div>

    @if (!empty($flyer->editorial_overview))
    <!-- Editorial Overview — Auto-linked contextual internal links -->
    <section class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-black text-[#023b55]">نظرة سريعة على العرض</h2>
        @php
            $cleanEditorial = trim((string) $flyer->editorial_overview);
            $cleanEditorial = (string) preg_replace("/[ \t]+\n/u", "\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/\n{3,}/u", "\n\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/:\n\n-/u", ":\n- ", $cleanEditorial);
        @endphp
        <div class="prose prose-sm max-w-none text-[13px] leading-6 text-slate-700 prose-headings:text-[#023b55] prose-strong:text-[#023b55] prose-p:my-2 prose-ul:my-2 prose-li:my-1 prose-a:text-[#039652] prose-a:font-bold">
            {!! app(\App\Services\AutoInternalLinkerService::class)->linkify($cleanEditorial, $flyer) !!}
        </div>
    </section>
    @endif

    <style>#flyer-lightbox:not(.hidden){display:flex}#flyer-lightbox.hidden{display:none} html{scroll-behavior:smooth} tr:target, tr.highlight-target{background-color:rgba(3,150,82,0.10)!important; transition:background-color .3s ease}</style>
    <script>
    // Deep-link highlight for #item-{id} from hot deals
    document.addEventListener('DOMContentLoaded', function(){
        function highlightHash(){
            const hash = location.hash;
            if(hash && hash.startsWith('#item-')){
                const el = document.querySelector(hash);
                if(el){
                    el.classList.add('highlight-target');
                    el.scrollIntoView({behavior:'smooth', block:'center'});
                    setTimeout(function(){ el.classList.remove('highlight-target'); }, 2000);
                }
            }
        }
        highlightHash();
        window.addEventListener('hashchange', highlightHash);
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const pages = @json($flyer->pages->sortBy('page_number')->values()->map(fn($p) => ['src' => \App\Support\R2Url::asset($p->image_path), 'alt' => $cleanTitle.' - صفحة '.$p->page_number]));
        if(!pages.length) return;
        let current = 0;
        let zoomed = false;
        const lightbox = document.getElementById('flyer-lightbox');
        const img = document.getElementById('lightbox-image');
        const indicator = document.getElementById('lightbox-indicator');
        const stage = document.getElementById('lightbox-stage');
        const btnClose = document.getElementById('lightbox-close');
        const btnPrev = document.getElementById('lightbox-prev');
        const btnNext = document.getElementById('lightbox-next');
        const btnZoom = document.getElementById('lightbox-zoom');
        const triggers = document.querySelectorAll('.lightbox-trigger');
        if(!lightbox || !img) return;
        function update(){
            const p = pages[current];
            img.src = p.src;
            img.alt = p.alt;
            indicator.textContent = 'صفحة ' + (current + 1) + ' من ' + pages.length;
            btnPrev.style.display = current === 0 ? 'none' : 'block';
            btnNext.style.display = current === pages.length - 1 ? 'none' : 'block';
            setZoom(false);
        }
        function setZoom(on){
            zoomed = on;
            img.style.transform = zoomed ? 'scale(2)' : 'scale(1)';
            img.style.cursor = zoomed ? 'zoom-out' : 'zoom-in';
            stage.style.overflow = zoomed ? 'auto' : 'hidden';
            btnZoom.innerHTML = zoomed
                ? '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"/></svg>'
                : '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>';
        }
        function open(idx){
            current = idx;
            update();
            lightbox.classList.remove('hidden');
            lightbox.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', onKey);
        }
        function close(){
            lightbox.classList.add('hidden');
            lightbox.classList.remove('flex');
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onKey);
            setZoom(false);
        }
        function onKey(e){
            if(lightbox.classList.contains('hidden')) return;
            if(e.key === 'Escape') close();
            else if(e.key === 'ArrowLeft') { if(current < pages.length - 1){ current++; update(); } }
            else if(e.key === 'ArrowRight') { if(current > 0){ current--; update(); } }
            else if(e.key === '+' || e.key === '=' ) setZoom(true);
            else if(e.key === '-' || e.key === '_') setZoom(false);
        }
        triggers.forEach(function(btn){
            btn.addEventListener('click', function(){ open(parseInt(btn.getAttribute('data-index'),10)); });
        });
        btnClose.addEventListener('click', close);
        btnPrev.addEventListener('click', function(){ if(current > 0){ current--; update(); }});
        btnNext.addEventListener('click', function(){ if(current < pages.length - 1){ current++; update(); }});
        btnZoom.addEventListener('click', function(){ setZoom(!zoomed); });
        img.addEventListener('click', function(){ setZoom(!zoomed); });
        lightbox.addEventListener('click', function(e){ if(e.target === lightbox) close(); });
    });
    </script>

    <!-- جدول الأسعار والسلع المكتشفة في المجلة (Structured Items Table) - Navy/Green -->
    <section class="rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        <h2 class="mb-2 text-lg font-black text-[#023b55]">جدول السلع والأسعار المفصلة في هذا العرض</h2>
        <p class="mb-4 text-xs text-slate-500 flex flex-wrap items-center gap-2">
            <span>تم استخراج وتدقيق هذه الأسعار تلقائياً عبر الذكاء الاصطناعي.</span>
            <span class="inline-flex items-center gap-1 rounded-md bg-[#039652]/10 px-2 py-0.5 font-bold text-[#039652]">
                🕒 آخر رصد وتحديث للأسعار: {{ $flyer->updated_at?->timezone('Africa/Cairo')->format('Y/m/d h:i A') }} ({{ $flyer->updated_at?->timezone('Africa/Cairo')->diffForHumans() }})
            </span>
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="border-b border-[#023b55]/10 bg-[#023b55]/5 text-[#023b55]">
                    <tr>
                        <th class="p-3 font-bold">اسم السلعة</th>
                        <th class="p-3 font-bold">الماركة</th>
                        <th class="p-3 font-bold">الوحدة</th>
                        <th class="p-3 font-bold">سعر العرض</th>
                        <th class="p-3 font-bold">السعر القديم</th>
                        <th class="p-3 font-bold">نسبة التوفير</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium text-slate-800">
                    @forelse ($flyer->items as $item)
                        <tr id="item-{{ $item->id }}" class="hover:bg-[#039652]/5">
                            <td class="p-3 font-bold text-[#023b55]">
                                <div>{{ $item->product_name }}</div>
                                @php $compareQuery = $item->brand?->name ?: \Illuminate\Support\Str::words($item->product_name, 2, ''); $compareQuery = trim((string) $compareQuery); @endphp
                                @if ($compareQuery !== '')
                                    <a href="{{ route('home', ['q' => $compareQuery]) }}" rel="nofollow" class="mt-1 inline-flex items-center gap-1 rounded-full border border-[#039652]/20 bg-[#039652]/10 px-2 py-0.5 text-[10px] font-bold text-[#039652] hover:bg-[#039652] hover:text-white transition">قارن الأسعار 🔍</a>
                                @endif
                            </td>
                            <td class="p-3 text-slate-500">{{ $item->brand?->name ?: '—' }}</td>
                            <td class="p-3 text-slate-500">{{ $item->unit ?: 'قطعة' }}</td>
                            <td class="p-3 text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</td>
                            <td class="p-3 text-slate-400">
                                @if ($item->old_price)
                                    <span class="line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-3">
                                @if ($item->discount_percent)
                                    @php $isSuper = (float) $item->discount_percent > 30; @endphp
                                    <span class="rounded px-2 py-0.5 text-[11px] font-bold {{ $isSuper ? 'bg-[#fcc023]/20 text-slate-900 border border-[#fcc023]/30' : 'bg-[#039652]/10 text-[#039652]' }}">
                                        خصم {{ round((float) $item->discount_percent) }}%
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-6 text-center text-slate-400">لا توجد سلع مسجلة لهذه المجلة بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- Dynamic Cross-Linking Widgets -->
    @if (isset($sameRetailerFlyers) && $sameRetailerFlyers->isNotEmpty())
    <section class="mt-10 rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">عروض أخرى سارية اليوم من {{ $flyer->retailer->name }}</h2>
            <a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="text-xs font-bold text-[#039652] hover:underline">عرض الكل ←</a>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($sameRetailerFlyers as $related)
                @php $cov = $related->coverPage; $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $related->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $related->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                        <span class="absolute right-2 top-2 rounded-lg bg-[#023b55] px-2 py-1 text-[10px] font-bold text-white">{{ $related->total_pages }} صفحة</span>
                    </div>
                    <div class="p-3">
                        <div class="text-[11px] font-bold text-[#039652]">{{ $related->retailer->name }}</div>
                        <h3 class="mt-1 line-clamp-2 text-xs font-black text-[#023b55] group-hover:text-[#039652]">{{ $related->title }}</h3>
                        <div class="mt-2 text-[11px] font-medium text-slate-500">حتى {{ \Carbon\Carbon::parse($related->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @if (isset($competitorFlyers) && $competitorFlyers->isNotEmpty())
    <section class="mt-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
        <div class="mb-4">
            <h2 class="text-lg font-black text-[#023b55]">عروض سلاسل السوبرماركت الأخرى السارية اليوم بمصر</h2>
            <p class="mt-1 text-xs text-slate-500">قارن عروض نفس الفترة من منافسين آخرين</p>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($competitorFlyers as $comp)
                @php $cov = $comp->coverPage; $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $comp->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $comp->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                        <span class="absolute left-2 top-2 rounded-full bg-white/90 px-2 py-0.5 text-[10px] font-black text-[#023b55] border border-slate-200">{{ $comp->retailer->name }}</span>
                    </div>
                    <div class="p-3">
                        <h3 class="line-clamp-2 text-xs font-black text-[#023b55] group-hover:text-[#039652]">{{ $comp->title }}</h3>
                        <div class="mt-1 text-[11px] text-slate-500">{{ \Carbon\Carbon::parse($comp->valid_from)->format('d/m') }} → {{ \Carbon\Carbon::parse($comp->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4 text-center">
            <a href="{{ route('home') }}" class="inline-flex rounded-xl border border-[#023b55]/15 bg-[#023b55]/5 px-4 py-2 text-xs font-bold text-[#023b55] hover:bg-[#039652] hover:text-white hover:border-[#039652] transition">تصفح كل العروض السارية →</a>
        </div>
    </section>
    @endif

    @if (isset($archiveFlyers) && $archiveFlyers->isNotEmpty())
    <section class="mt-6 rounded-2xl border border-[#e2e8f0] bg-slate-50 p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">أرشيف عروض {{ $flyer->retailer->name }} السابقة</h2>
            <span class="rounded-full bg-white border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600">{{ $archiveFlyers->count() }} مجلة للسجل السعري</span>
        </div>
        <p class="mb-4 text-xs text-slate-500">للمقارنة ومعرفة تطور الأسعار قبل الشراء — توثيق تاريخي لآخر 30 يوم</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($archiveFlyers as $arc)
                @php $cov = $arc->coverPage; $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $arc->slug) }}" class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white opacity-95 hover:opacity-100 hover:border-[#023b55]/20 transition">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $arc->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover grayscale hover:grayscale-0 transition">
                        <span class="absolute inset-0 bg-slate-900/5"></span>
                        <span class="absolute right-2 top-2 rounded-lg bg-slate-700 px-2 py-1 text-[10px] font-bold text-white">منتهي {{ \Carbon\Carbon::parse($arc->valid_until)->format('d/m') }}</span>
                    </div>
                    <div class="p-3">
                        <h3 class="line-clamp-2 text-xs font-bold text-slate-700">{{ $arc->title }}</h3>
                        <div class="mt-1 text-[11px] text-slate-500">{{ \Carbon\Carbon::parse($arc->valid_from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($arc->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
    @endif
</x-layouts.app>
````
