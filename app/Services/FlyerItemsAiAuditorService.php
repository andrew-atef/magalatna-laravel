<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Services\ProductDataNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lightweight 1-call-per-flyer AI Text Auditor.
 *
 * Runs AFTER deterministic normalization + dedup, uses a high-RPD text model
 * (gemini-3.5-flash-lite, 500 RPD free tier) to catch cross-item inconsistencies
 * that single-item OCR misses. NEVER touches prices. Guardrails prevent
 * over-deletion and ensure graceful degradation on API failure.
 */
final class FlyerItemsAiAuditorService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const TIMEOUT_SECONDS = 45;
    private const MAX_ITEMS_PER_CALL = 100;
    private const MAX_DROP_RATIO = 0.25; // 25% circuit breaker

    /**
     * Audit and repair a flyer's items via a single Gemini text call.
     *
     * @return array{applied: int, dropped: int, skipped: bool}
     */
    public function audit(Flyer $flyer): array
    {
        $items = $flyer->items()
            ->where('sale_price', '>', 0)
            ->orderBy('id')
            ->get([
                'id', 'product_name', 'brand_id', 'category', 'unit', 'sale_price'
            ]);

        if ($items->isEmpty()) {
            return ['applied' => 0, 'dropped' => 0, 'skipped' => true];
        }

        // Load brand names for the payload
        $brandIds = $items->pluck('brand_id')->filter()->unique()->values()->all();
        $brandNames = [];
        if ($brandIds !== []) {
            $brandNames = \App\Models\Brand::whereIn('id', $brandIds)
                ->pluck('name', 'id')
                ->all();
        }

        $payloadItems = $items->map(function (FlyerItem $item) use ($brandNames): array {
            return [
                'id' => (int) $item->id,
                'name' => (string) $item->product_name,
                'brand' => $item->brand_id !== null && isset($brandNames[$item->brand_id])
                    ? (string) $brandNames[$item->brand_id]
                    : null,
                'category' => (string) ($item->category ?? ''),
                'unit' => (string) ($item->unit ?? ''),
                'sale_price' => (float) $item->sale_price,
            ];
        })->values()->all();

        // Chunk if > 100 items
        $chunks = array_chunk($payloadItems, self::MAX_ITEMS_PER_CALL);
        $totalApplied = 0;
        $totalDropped = 0;

        foreach ($chunks as $chunk) {
            $result = $this->callAuditor($chunk, (string) $flyer->title);
            if ($result === null) {
                return ['applied' => $totalApplied, 'dropped' => $totalDropped, 'skipped' => true];
            }

            $applied = $this->applyFixes($flyer, $chunk, $result['fixes'] ?? []);
            $dropped = $this->applyDrops($flyer, $chunk, $result['drop_ids'] ?? []);

            $totalApplied += $applied;
            $totalDropped += $dropped;
        }

        return ['applied' => $totalApplied, 'dropped' => $totalDropped, 'skipped' => false];
    }

    /**
     * Call Gemini auditor model with the items payload.
     */
    private function callAuditor(array $items, string $flyerTitle): ?array
    {
        try {
            $model = (string) config('services.gemini.auditor_model', 'gemini-3.5-flash-lite');
            $apiKey = (string) config('services.gemini.key', '');

            if ($apiKey === '') {
                Log::debug('FlyerItemsAiAuditor: GEMINI_API_KEY not configured, skipping.');
                return null;
            }

            $prompt = $this->buildPrompt($items, $flyerTitle);

            $payload = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'topP' => 0.95,
                    'topK' => 32,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->auditorSchema(),
                ],
            ];

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                ->withOptions(['http_errors' => false])
                ->post(self::BASE_URL . '/' . $model . ':generateContent', $payload);

            if ($response->status() === 429) {
                Log::warning('FlyerItemsAiAuditor: rate limited (429), skipping.', [
                    'model' => $model,
                    'flyer_title' => $flyerTitle,
                ]);
                return null;
            }

            if (!$response->successful()) {
                Log::warning('FlyerItemsAiAuditor: HTTP error, skipping.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $body = (string) $response->body();
            if (trim($body) === '') {
                return null;
            }

            $raw = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('FlyerItemsAiAuditor: invalid JSON response.', ['error' => json_last_error_msg()]);
                return null;
            }

            $result = $this->extractJsonFromCandidate($raw);

            // Validate structure
            if (!is_array($result) || !isset($result['fixes']) || !isset($result['drop_ids'])) {
                return null;
            }

            return [
                'fixes' => is_array($result['fixes']) ? $result['fixes'] : [],
                'drop_ids' => is_array($result['drop_ids']) ? $result['drop_ids'] : [],
            ];
        } catch (Throwable $e) {
            Log::warning('FlyerItemsAiAuditor: exception, skipping.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build the auditor prompt.
     */
    private function buildPrompt(array $items, string $flyerTitle): string
    {
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are a quality auditor for Egyptian supermarket flyer data. The flyer "{${flyerTitle}}" has been deterministically normalized, but cross-item OCR inconsistencies may remain.

Review the following items (compact JSON: id, name, brand, category, unit, sale_price) and return ONLY a JSON object with two keys:
- "fixes": array of objects, each with {id, name?, brand?, category?} for items needing correction
- "drop_ids": array of integer IDs to drop (ONLY clear OCR garbage/hallucinations)

STRICT RULES:
1. NEVER modify sale_price or old_price.
2. Unify inconsistent OCR spellings within THIS flyer (e.g., "شايي"/"شاييني"/"شايلي" → "شايني").
3. Translate any pure-English names to natural Egyptian Supermarket Arabic:
   "Balady Beef Escalope" → "اسكالوب بقري بلدي"
   "Sliced Liver" → "كبدة شرائح"
   "Dragon Fruit" → "دراجون فروت"
   "Balady Beef Mince" → "لحم مفروم بقري بلدي"
   Preserve model numbers (UA85006LA, 55", 65", etc.).
4. Clear brand (set to null) for fake non-brand labels:
   "Balady", "Banana Local", "Avocado", "Dragon Fruit", "Iceberg", "Local", "Fresh", "Imported", "بلدي", "مستورد", "طازج", "عادي", "حار"
5. Fix obviously wrong categories (must be from: oils_ghee, dairy_cheese, meat_poultry, groceries, beverages_snacks, cleaning_laundry, personal_care, home_appliances, automotive_tools, electronics, apparel_clothing, other).
6. drop_ids ONLY for clear OCR garbage (promo text parsed as product, impossible price/item mismatch like "شاي" at 19999, non-product headers).

INPUT ITEMS:
{$itemsJson}

OUTPUT JSON SCHEMA:
{"fixes": [{"id": 123, "name": "corrected name", "brand": "corrected brand or null", "category": "valid_category"}], "drop_ids": [456, 789]}
PROMPT;
    }

    /**
     * Apply fixes to items.
     */
    private function applyFixes(Flyer $flyer, array $chunk, array $fixes): int
    {
        if ($fixes === []) {
            return 0;
        }

        $applied = 0;
        $chunkById = [];
        foreach ($chunk as $item) {
            $chunkById[$item['id']] = $item;
        }

        foreach ($fixes as $fix) {
            $id = (int) ($fix['id'] ?? 0);
            if ($id === 0 || !isset($chunkById[$id])) {
                continue;
            }

            $item = FlyerItem::find($id);
            if ($item === null || $item->flyer_id !== $flyer->id) {
                continue;
            }

            $changed = false;

            if (isset($fix['name']) && is_string($fix['name']) && trim($fix['name']) !== '') {
                $newName = ProductDataNormalizer::cleanProductName(trim($fix['name']));
                if ($newName !== '' && $newName !== (string) $item->product_name) {
                    $item->product_name = $newName;
                    $item->normalized_name = \App\Support\ArabicNormalizer::normalize($newName) ?: $newName;
                    $changed = true;
                }
            }

            if (isset($fix['brand'])) {
                $brand = $fix['brand'] === null || $fix['brand'] === '' ? null : trim((string) $fix['brand']);
                if ($brand !== null && $brand !== '') {
                    $canonical = ProductDataNormalizer::canonicalBrandName($brand, (string) $item->product_name);
                    if ($canonical !== null) {
                        $brandId = $this->resolveBrandId($canonical);
                        if ($brandId !== null && $brandId !== $item->brand_id) {
                            $item->brand_id = $brandId;
                            $changed = true;
                        }
                    } elseif ($item->brand_id !== null) {
                        $item->brand_id = null;
                        $changed = true;
                    }
                } elseif ($item->brand_id !== null) {
                    $item->brand_id = null;
                    $changed = true;
                }
            }

            if (isset($fix['category']) && is_string($fix['category']) && trim($fix['category']) !== '') {
                $cat = trim($fix['category']);
                $validCats = ProductDataNormalizer::CATEGORY_VALUES;
                if (in_array($cat, $validCats, true) && $cat !== (string) $item->category) {
                    $item->category = $cat;
                    $changed = true;
                }
            }

            if ($changed) {
                $item->saveQuietly();
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * Apply drops with 25% circuit breaker.
     */
    private function applyDrops(Flyer $flyer, array $chunk, array $dropIds): int
    {
        if ($dropIds === []) {
            return 0;
        }

        $chunkIds = array_column($chunk, 'id');
        $validDrops = array_values(array_intersect($dropIds, $chunkIds));

        // Circuit breaker: never drop more than 25% of items in this chunk
        $maxDrops = max(1, (int) floor(count($chunk) * self::MAX_DROP_RATIO));
        if (count($validDrops) > $maxDrops) {
            Log::warning('FlyerItemsAiAuditor: drop_ids exceeds 25% limit, truncating.', [
                'flyer_id' => $flyer->id,
                'requested' => count($validDrops),
                'allowed' => $maxDrops,
            ]);
            $validDrops = array_slice($validDrops, 0, $maxDrops);
        }

        if ($validDrops === []) {
            return 0;
        }

        FlyerItem::whereIn('id', $validDrops)
            ->where('flyer_id', $flyer->id)
            ->delete();

        return count($validDrops);
    }

    /**
     * Resolve canonical brand name to a Brand row ID.
     */
    private function resolveBrandId(string $canonicalName): ?int
    {
        $brandSlug = \Illuminate\Support\Str::slug($canonicalName);
        if ($brandSlug === '') {
            $brandSlug = 'brand-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ulid()->toString());
        }

        try {
            $brand = \App\Models\Brand::firstOrCreate(
                ['name' => $canonicalName],
                ['slug' => $this->uniqueBrandSlug($brandSlug)]
            );
            return (int) $brand->id;
        } catch (Throwable $e) {
            try {
                $brand = \App\Models\Brand::query()->where('name', $canonicalName)->first();
                return $brand?->id;
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function uniqueBrandSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 0;
        while (\App\Models\Brand::where('slug', $slug)->exists()) {
            $counter++;
            $slug = $baseSlug . '-' . $counter;
            if ($counter > 10) {
                $slug = $baseSlug . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ulid()->toString());
                break;
            }
        }
        return $slug;
    }

    /**
     * Auditor response schema.
     */
    private function auditorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fixes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string', 'nullable' => true],
                            'brand' => ['type' => 'string', 'nullable' => true],
                            'category' => ['type' => 'string', 'nullable' => true],
                        ],
                        'required' => ['id'],
                    ],
                ],
                'drop_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['fixes', 'drop_ids'],
        ];
    }

    /**
     * Extract JSON from Gemini candidate response.
     */
    private function extractJsonFromCandidate(array $raw): array
    {
        try {
            $candidates = $raw['candidates'] ?? null;
            if (is_array($candidates) && isset($candidates[0]['content']['parts'][0]['text'])) {
                $text = (string) $candidates[0]['content']['parts'][0]['text'];
                $decoded = json_decode($text, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
            // Fallback
            if (isset($raw['fixes']) || isset($raw['drop_ids'])) {
                return $raw;
            }
            return ['fixes' => [], 'drop_ids' => []];
        } catch (Throwable) {
            return ['fixes' => [], 'drop_ids' => []];
        }
    }
}