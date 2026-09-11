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

    private const MAX_RETRIES = 3;

    private const INITIAL_RETRY_DELAY_MS = 2000;

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
                    $waitMs = $retryAfter > 0 ? $retryAfter * 1000 : $delayMs;

                    Log::warning('Gemini API 429 Too Many Requests.', [
                        'attempt' => $attempt,
                        'retry_after' => $retryAfter,
                        'wait_ms' => $waitMs,
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new RuntimeException('Gemini API rate limited (429) after '.$attempt.' attempts.');
                    }

                    usleep($waitMs * 1000);
                    $delayMs *= 2;

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

        // Enforce 2 sentences max and no English
        if (preg_match('/[a-zA-Z]/', $text)) {
            Log::warning('Gemini BLUF contained English, falling back to Arabic template.', ['text' => $text]);
            throw new RuntimeException('BLUF contains English');
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
        if (preg_match('/[a-zA-Z]/', $text)) {
            throw new RuntimeException('Editorial contains English');
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
     * Classification prompt with Egyptian retail domain knowledge — Flash 1-Day aware, teaser-proof, no date invention.
     */
    private function classificationPrompt(int $imageCount = 1): string
    {
        $today = now('Africa/Cairo')->format('Y-m-d');

        return <<<PROMPT
You are the Gatekeeper for عروض نت (Egyptian retail flyer aggregator).

Current date (Africa/Cairo): {$today}. Assume year 2026 if year not explicitly visible in post/image.
TOTAL_IMAGES_IN_POST is provided alongside POST_TEXT — use it for Flash 1-Day detection.

Classify the POST_TEXT (and optional first image) as either a real retail flyer/catalog or NOT.

A REAL FLYER = A supermarket / hypermarket / pharmacy / electronics retailer catalog that contains:
- Multiple product offers with prices (e.g., Carrefour, Hyperone, Kazyon, BIM, Panda, Seoudi, Spinneys)
- Titles like "عروض", "كتالوج", "مجلة العروض", "Offers", "Savings"
- Valid date ranges (e.g., "من 10 سبتمبر حتى 20 سبتمبر", "valid from/to") OR a single specific day for Flash deals (see below)
- Sometimes governorate-specific availability.

Flash 1-Day Deals / عرض اليوم الواحد / عروض طازج — VALID FLYER DEFINITION:
An album with multiple images (TOTAL_IMAGES_IN_POST >= 2) where each image features a discounted staple commodity for a single specific day (e.g., "ساري يوم الأربعاء 9 سبتمبر فقط" or "عرض الإثنين 7 سبتمبر" or "عروض طازج اليوم") IS A VALID FLYER (is_flyer = true). This is NOT a single product ad — the album as a whole is the catalog. For single-day offers, set both valid_from and valid_until to that exact same date (e.g., "الأربعاء 9 سبتمبر" => valid_from=2026-09-09 and valid_until=2026-09-09).

NOT A FLYER = Contest / giveaway ("مسابقة", "اربح", "جائزة"), recipe ("وصفة"), branch opening/announcement ("افتتاح فرع جديد", "مواعيد العمل"), meme/joke, job ad, political/news, single product ad without catalog context (single image with one product and no date range, unless it is part of a multi-image Flash album as defined above).

STRICTLY REJECT Teaser/Reminder spam — MUST return is_flyer = false:
- Any post that says "قبل ما العروض والكميات تخلص", "قبل ما العروض تخلص", "فاضلك ايه", "فاضل ايه", "اشتري اللي ناقصك", "الحق العروض", "قبل ما تخلص"
- Any post that links to an older post for remaining offers: "باقي العروض في البوست ده", "باقي العروض هنا", "شوف باقي العروض", "البوست ده فيه باقي"
- Any reminder that contains no new explicit validity dates and only urges to buy before expiry — even if it mentions a retailer name.

Instructions:
- is_flyer = true ONLY if this is a genuine multi-product price catalog OR a Flash 1-Day album (TOTAL_IMAGES_IN_POST >=2 with single-day staple offers) as defined above.
- reason = concise Arabic explanation (1 sentence) why you classified as flyer or not.
- flyer_title = STRICTLY ENFORCE Egyptian SEO Title Formulas (ANTI-CANNIBALIZATION):
  * FORMULA A (Single-Day / Flash Deals):
    "عروض [اسم المتجر] [اسم اليوم بالعربية] [رقم اليوم] [اسم الشهر بالعربية] [السنة] | عرض اليوم الواحد"
    Example: "عروض كازيون الأربعاء 9 سبتمبر 2026 | عرض اليوم الواحد"
  * FORMULA B (Multi-Day / Weekly Magazine):
    "عروض [اسم المتجر] من [يوم البداية] حتى [يوم النهاية] [اسم الشهر بالعربية] [السنة] | [اسم المجلة/المناسبة إن وجدت]"
    Example: "عروض كازيون من 8 حتى 15 سبتمبر 2026 | مجلة العودة للمدارس"
    Example without specific theme: "عروض كارفور من 10 حتى 20 سبتمبر 2026 | مجلة العروض والتوفير"
  * Hard Constraint: NEVER return shallow titles like "عروض كازيون" or "عروض المدرسة". The title MUST include the exact dates and month in Arabic. Keep under 65 chars where possible, front-load brand and dates.
- valid_from / valid_until = Extract ONLY explicit, verifiable dates stated in caption or image. Normalize to YYYY-MM-DD. CRITICAL: If year not visible, use 2026 (current year). Today is {$today}. Examples: "8 سبتمبر حتى 14 سبتمبر" => 2026-09-08 to 2026-09-14; "الأربعاء 9 سبتمبر فقط" => 2026-09-09 to 2026-09-09. NEVER invent, estimate, or hallucinate dates. If no explicit, verifiable date range or specific single day is stated, you MUST return is_flyer = false, valid_from = null, and valid_until = null.
- EXCEPTION — "Until Stock Lasts" (حتى نفاذ الكمية): Keep the anti-spam rule that forbids inventing dates for teasers ("قبل ما العروض تخلص"). BUT if the post contains a clear explicit START date (valid_from) AND the phrase "حتى نفاذ الكمية" (or variants "حتى نفاد الكمية", "حتي نفاذ الكميه", "حتى نفاذ الكميه", "until stock lasts", "while stocks last") you MUST accept it as a valid flyer (is_flyer = true). For this exception ONLY, calculate valid_until by adding exactly 10 days to valid_from, output as YYYY-MM-DD. Example: "ساري من 18/8/2026 حتى نفاذ الكمية" => valid_from="2026-08-18", valid_until="2026-08-28". Reinforce: If BOTH valid_from and valid_until are missing/cannot be extracted, you MUST reject (is_flyer=false) — no exception applies.
- EGYPTIAN RETAIL TEMPORAL PARSING — MUST explicitly support all 4 core structures:
  1. Single-Day Flash Deals ("يوم السبت 12 سبتمبر فقط", "عرض اليوم الواحد"): Set both valid_from and valid_until to that exact day (e.g., 2026-09-12).
  2. Two-Day Weekend Offers ("يوم الجمعة 11 سبتمبر ويوم السبت 12 سبتمبر"): valid_from="2026-09-11", valid_until="2026-09-12".
  3. Cross-Month Campaigns ("من الجمعة 11 سبتمبر حتى الخميس 1 أكتوبر"): Correctly increment the month for end dates: valid_from="2026-09-11", valid_until="2026-10-01".
  4. Open-ended with Start Date ("ساري من 10 سبتمبر وحتى نفاذ الكمية"): Calculate valid_until as valid_from + 10 days (e.g., 2026-09-20) — same as "Until Stock Lasts" exception above.
- Applicable only if is_flyer = true and an explicit date is present (including the 10-day exception above). Otherwise is_flyer must be false.
- applicable_governorates = List of Egyptian governorates mentioned (Arabic names e.g., "القاهرة", "الجيزة", "الإسكندرية" or English "Cairo","Giza"). Return [] if applies to all Egypt / no restriction mentioned.

Be strict: If unsure, or if no explicit date is verifiable (and no "حتى نفاذ الكمية" with a clear start date), prefer is_flyer=false. Never invent dates.
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
            if ($salePrice === null) {
                Log::warning('Skipping OCR item without valid sale_price.', ['index' => $idx, 'product' => $productName]);

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
