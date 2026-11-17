<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FlyerItem;
use App\Services\ProductDataNormalizer as OcrProductDataNormalizer;

/**
 * Pure, side-effect-free product-text helpers shared by the flyer views
 * and SEO metadata builders. Every method is O(strlen) — never touches
 * the database, so it is safe to call inside Blade loops.
 */
final class ProductDataNormalizer
{
    /**
     * "سعر الوحدة" standardisation: quantity + physical unit parsed OUT of
     * the printed product name (e.g. "زيت كريستال 1 لتر" → per LITRE price,
     * "جبنة رومي 500 جم" → per KILO price). Mixed quantities become instantly
     * comparable for shoppers AND machine-readable for AI price agents.
     *
     * Legacy view contract: a printed pack of exactly "1" ("1 لتر") STILL
     * renders its per-unit price (which equals the sale price) — the OCR
     * pipeline's stricter "only when quantity != 1" rule is the default in
     * the service; this thin wrapper opts back in via $includeQuantityOne.
     *
     * @return array{amount: float, unit: string}|null null when the product
     *         carries no parsable weight/volume (e.g. "قطعة") — the caller
     *         renders "—" instead of fabricating a unit price.
     */
    public static function extractStandardUnitPrice(FlyerItem $item): ?array
    {
        return OcrProductDataNormalizer::extractStandardUnitPrice(
            (string) $item->product_name,
            $item->unit !== null ? (string) $item->unit : null,
            (float) $item->sale_price,
            true,
        );
    }

    /**
     * Smart "قارن الأسعار" query: product TYPE + BRAND words only — weight
     * tokens ("1 لتر", "500 جم") and pack words are stripped so the homepage
     * search actually matches sibling SKUs of the same product family.
     *
     * Example: "زيت كريستال 1 لتر" (brand كريستال) → "زيت كريستال";
     *          "حليب المراعي كامل الدسم 1 لتر" → "حليب المراعي".
     */
    public static function searchQuery(string $productName, ?string $brand = null): string
    {
        $stopTokens = [
            'جم', 'جرام', 'كجم', 'كيلو', 'كغ', 'لتر', 'ليتر', 'مل', 'ملي', 'م',
            'حبة', 'قطعة', 'قطعه', 'علبة', 'علبه', 'كيس', 'كرتونة', 'كرتونه',
            'عبوة', 'عبوه', 'عدد', 'جنيه', 'ج', 'م', 'باقه', 'شيكارة',
        ];

        $words = [];
        foreach (preg_split('/[\s\/\-–]+/u', trim($productName)) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || preg_match('/\d/u', $token) === 1) {
                continue; // pure numbers / pack sizes ("1", "500جم")
            }
            $normalized = ArabicNormalizer::normalize($token);
            if (in_array($normalized, $stopTokens, true)) {
                continue;
            }
            $words[] = $token;
            if (count($words) === 2) {
                break;
            }
        }

        $query = implode(' ', $words);

        // Keep the brand when the two words do not already carry it.
        $brand = trim((string) $brand);
        if ($brand !== '' && mb_strlen($brand) >= 2 && ! str_contains($query, $brand)) {
            $query = trim($query !== '' ? $query.' '.$brand : $brand);
        }

        return mb_substr($query, 0, 60);
    }

    /**
     * Lightweight client-side table bucket — one of five fixed filter keys
     * rendered into data-cat attributes (zero-JS classification cost).
     *
     * @return 'oil'|'dairy'|'meat'|'clean'|'grocery'
     */
    public static function classifyProduct(string $productName): string
    {
        $name = ArabicNormalizer::normalize($productName);

        $buckets = [
            'oil' => ['زيت', 'زيوت', 'سمن'],
            'dairy' => ['حليب', 'لبن', 'جبن', 'زبادي', 'قشطه', 'كريمه', 'موزاريلا', 'رومي', 'بيض', 'بريه', 'استاني'],
            'meat' => ['لحم', 'فراخ', 'دجاج', 'سجق', 'كفت', 'برجر', 'جمبري', 'سمك', 'مجمدات', 'شاورما', 'بانيه', 'بيف', 'استربس', 'ناجتس', 'هوت دوغ'],
            'clean' => ['مسحوق', 'منظف', 'صابون', 'شامبو', 'كلور', 'مطهر', 'معقم', 'مناديل', 'غسيل', 'جيل', 'ملمع', 'مبيض', 'عنايه', 'منقم'],
        ];

        foreach ($buckets as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $key;
                }
            }
        }

        return 'grocery';
    }

    /**
     * Word-safe excerpt cut for <meta name="description"> — never cuts a
     * word in half; appends a single-character ellipsis when trimmed.
     */
    public static function metaExcerpt(string $text, int $limit = 155): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= $limit * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t.،,؛:").'…';
    }
}
