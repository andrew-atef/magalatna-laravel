<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\FlyerItem;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic post-OCR product-data pipeline (Phase 1 unified).
 *
 * Runs AFTER Gemini Vision, entirely in code — the AI's job is to READ the
 * flyer; this class decides what the data MEANS:
 *
 *  - cleanProductName():        digits, OCR letter-for-digit unit errors,
 *                               canonical brand/product typo corrections.
 *  - canonicalBrandName():      alias map + dynamic fuzzy snap to existing
 *                               Brand rows + name-scan inference.
 *  - inferCategory():           deterministic Egyptian-retail keyword
 *                               classifier that GUARANTEES a clean category
 *                               (AI's own category is only a fallback).
 *  - dedupKey()/areSameProduct(): normalized-stem duplicate matching across
 *                               cover-vs-inner-page OCR double reads.
 *  - extractStandardUnitPrice(): "سعر الوحدة" per 1 كجم / 1 لتر.
 *
 * All methods are static and side-effect-free unless documented; the only
 * I/O is the CACHED `auto_linker:brands` Brand-name list (invalidated by
 * BrandObserver on save/delete).
 */
final class ProductDataNormalizer
{
    /** @var list<string> The complete closed category vocabulary. */
    public const CATEGORY_VALUES = [
        'oils_ghee',
        'dairy_cheese',
        'meat_poultry',
        'groceries',
        'beverages_snacks',
        'cleaning_laundry',
        'personal_care',
        'home_appliances',
        'automotive_tools',
        'other',
    ];

    private const BRAND_CACHE_KEY = 'auto_linker:brands';

    private const BRAND_CACHE_TTL = 86400; // 24h, invalidated by BrandObserver

    /**
     * Threshold for the fuzzy-stem duplicate rule (areSameProduct): two
     * price-identical rows whose normalized stems are ≥ 82% similar are the
     * same product re-read by OCR (cover vs inner page double-read).
     */
    private const STEM_SIMILARITY_THRESHOLD = 82.0;

    /**
     * Ordered category rules — FIRST match wins, so the order encodes the
     * conflict resolutions:
     *  - automotive BEFORE oils: "زيت محرك"/"ليكوي مولي" are motor oil.
     *  - personal_care BEFORE oils: "بديل زيت"/"زيت شعر" are HAIR products.
     *  - groceries ("زيتون" = table olives) keeps its keyword even though the
     *    oils rule group runs earlier — there is deliberately NO bare "زيت"
     *    keyword INSIDE this map, so "زيتون أخضر" can never be shadowed into
     *    oils_ghee by a generic oil match ("زيت زيتون" still hits the explicit
     *    olive-OIL keyword first and stays food).
     *    The bare "زيت" generic-oil match lives in inferCategory() as a
     *    POST-groceries FALLBACK: it runs only after groceries' "زيتون" has
     *    already had its chance, so plain "زيت عباد" (no explicit keyword)
     *    still lands in oils_ghee while "زيتون أخضر" stays a grocery.
     * Keywords are matched with str_contains on the normalized name.
     *
     * @return array<string, list<string>>
     */
    private static function categoryRules(): array
    {
        return [
            'automotive_tools' => [
                '5w', '10w', '15w', '20w', 'ليكوي مولي', 'موتور', 'محرك', 'سيارات',
                'بطارية سيارة', 'مساحات', 'زيت محرك', 'دريل', 'شنيور',
            ],
            'personal_care' => [
                'شامبو', 'بديل زيت', 'بلسم', 'سبراي', 'مزيل عرق', 'صابون',
                'معجون أسنان', 'فوط صحية', 'حفاضات', 'مولفكس', 'سوفي', 'اولويز', 'الفيف', 'اكس',
                // Cosmetic oils — must outrank oils_ghee ("زيت" is shared).
                'زيت شعر', 'زيت اطفال', 'دابر', 'فاتيكا',
            ],
            'cleaning_laundry' => [
                'مسحوق', 'برسيل', 'اريل', 'تايد', 'اوكسي', 'بريل', 'وفير', 'جنرال',
                'مناديل', 'كلور', 'منظف', 'سائل اطباق',
            ],
            'oils_ghee' => [
                'زيت خليط', 'زيت الخليط', 'زيت عباد', 'زيت عباد الشمس', 'زيت ذره', 'زيت الذره',
                'زيت قلي', 'زيت القلي', 'زيت زيتون', 'زيت الزيتون', 'زيت سلطة', 'زيت طعام',
                // Broader Egyptian cooking-oil brand/keyword coverage
                // (post-OCR normalized forms: ة→ه, أ→ا).
                'زيت عافيه', 'زيت هلا', 'زيت سلايت', 'زيت حلوه', 'زيت نباتي', 'زيوت',
                'زبد', 'سمن', 'كريستال', 'الممتاز', 'شطاره',
            ],
            'beverages_snacks' => [
                'عصير', 'بيبسي', 'كوكاكولا', 'سفن', 'ميرندا', 'مياه', 'شاي', 'قهوه',
                'نسكافيه', 'بن ', 'بسكويت', 'شوكولاته', 'كيك', 'دونتس', 'شيبس',
                'مربي', 'حلوه',
                // NOTE: "جبنتي" (Egyptian cheese brand, contains "جبن") is NOT
                // here — it belongs to dairy_cheese below.
            ],
            'dairy_cheese' => [
                'حليب', 'لبن', 'جبن', 'مثلثات', 'شيدر', 'موتزريلا', 'زبادي', 'رايب', 'قشطه',
            ],
            'meat_poultry' => [
                'دجاج', 'فراخ', 'بانيه', 'ستربس', 'مسحب', 'لحم', 'لحوم', 'مفروم',
                'سجق', 'برجر', 'لانشون', 'تونه', 'سمك', 'سلمون', 'جمبري',
            ],
            'groceries' => [
                'ارز', 'رز', 'سكر', 'دقيق', 'مكرونه', 'صلصه', 'عدس', 'فول', 'ملح',
                // Table olives ("زيتون أخضر") — plain زيتون must classify as a
                // grocery, and there is intentionally no bare "زيت" keyword
                // anywhere above that could shadow it.
                'زيتون',
            ],
            'home_appliances' => [
                'طقم طهي', 'جرانيت', 'تيفال', 'شاشه', 'ثلاجه', 'غساله', 'خلاط',
                'مروحه', 'بطانيه', 'مفرش', 'فوطه', 'كوب',
            ],
        ];
    }

    /**
     * Normalized filler phrases stripped from dedup stems — OCR prints pack
     * variants ("أنواع مختلفة", "عادي أو حار") that must not split identical
     * products into two rows.
     *
     * @var list<string>
     */
    private const STEM_FILLERS = ['انواع مختلفه', 'عادي او حار', 'حتي نفاذ الكميه', 'حتى نفاذ الكمية'];

    // -------------------------------------------------------------------------
    // 1. cleanProductName
    // -------------------------------------------------------------------------

    /**
     * Clean an OCR product name for STORAGE (display quality output):
     * Eastern/Persian digits → Western, decimal commas fixed, OCR
     * letter-for-digit unit errors repaired, and configurable canonical
     * typo corrections applied word-boundary-safe from config/ocr_aliases.
     */
    public static function cleanProductName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        // 1. Numeral systems + Arabic decimal comma ("١,٥" → "1.5").
        $name = ArabicNormalizer::toWesternDigits($name);

        // 2. OCR letter-for-digit unit errors:
        //    a standalone "ا" misread for "1" directly before a unit token
        //    ("ا كجم", "ا لتر", "ا ق") — never a real word (lookarounds).
        $name = (string) preg_replace(
            '/(?<![\p{L}\p{N}])ا\s*(كجم|كيلو|كغ|لتر|ليتر|مل|ملي|جم|جرام|ق)(?![\p{L}\p{N}])/u',
            '1 $1',
            $name,
        );

        //    a bare "ك" after a number is "كجم" ("5 ك" → "5 كجم"); a bare
        //    "ق" after a number is "قطعة" ("3 ق" → "3 قطعة").
        $name = (string) preg_replace(
            '/(?<![\p{L}\p{N}])(\d+(?:\.\d+)?)\s*ك(?![\p{L}\p{N}])/u',
            '$1 كجم',
            $name,
        );
        $name = (string) preg_replace(
            '/(?<![\p{L}\p{N}])(\d+)\s*ق(?![\p{L}\p{N}])/u',
            '$1 قطعة',
            $name,
        );

        // 3. Configurable canonical typo corrections (دولتين → دولفين …).
        foreach (self::productCorrections() as $wrong => $right) {
            $name = (string) preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote((string) $wrong, '/').'(?![\p{L}\p{N}])/u',
                (string) $right,
                $name,
            );
        }

        // 4. Whitespace tidy-up.
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    // -------------------------------------------------------------------------
    // 2. canonicalBrandName
    // -------------------------------------------------------------------------

    /**
     * Resolve the OCR brand to its canonical Brand name:
     *
     *  1. config/ocr_aliases.php alias map (normalized raw key).
     *  2. Dynamic fuzzy fallback: normalized raw ≥ 5 chars and mb-Levenshtein
     *     ≤ 1 against a cached existing Brand name → snap to it.
     *  3. Printed raw passes through untouched (upstream resolves brand_id).
     *
     * When `$rawBrand` is empty/null the CLEANED PRODUCT NAME is scanned for
     * any known canonical brand so `brand_id` is inferred automatically
     * ("زيت عافية 1 لتر" with no brand field → "عافية").
     */
    public static function canonicalBrandName(?string $rawBrand, string $cleanedProductName): ?string
    {
        $raw = trim((string) $rawBrand);

        if ($raw !== '') {
            $key = self::brandKey($raw);

            $aliases = self::brandAliases();
            if (isset($aliases[$key])) {
                return $aliases[$key];
            }

            $snapped = self::fuzzySnapToBrand($key);
            if ($snapped !== null) {
                return $snapped;
            }

            return $raw;
        }

        // Empty raw brand → scan the cleaned name for known canonical brands.
        foreach (self::knownCanonicalBrands() as $canonical) {
            if (self::containsWord($cleanedProductName, $canonical)) {
                return $canonical;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // 3. inferCategory
    // -------------------------------------------------------------------------

    /**
     * Deterministic Egyptian-retail category. The keyword classifier runs
     * FIRST and OVERRIDES the AI's own answer — it guarantees a clean,
     * stable category; `$aiCategory` is only consulted when no keyword
     * matched, and only when it is a member of the closed vocabulary.
     */
    public static function inferCategory(string $productName, ?string $aiCategory = null): string
    {
        $name = ArabicNormalizer::normalize(ArabicNormalizer::toWesternDigits($productName));
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));

        if ($name !== '') {
            foreach (self::categoryRules() as $category => $keywords) {
                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($name, $keyword)) {
                        return $category;
                    }
                }
            }

            // Bare "زيت" generic-oil FALLBACK — deliberately checked AFTER the
            // ordered scan so groceries' "زيتون" (table olives) always wins:
            // anything containing "زيتون" has already returned groceries above,
            // and motor/hair oils hit their explicit rules even earlier. Only
            // unclassified names like "زيت 1 لتر" or "زيت نباتي خليط" reach
            // this fallback and land in oils_ghee.
            if (str_contains($name, 'زيت')) {
                return 'oils_ghee';
            }
        }

        if (is_string($aiCategory)) {
            $ai = trim(mb_strtolower($aiCategory));

            if (in_array($ai, self::CATEGORY_VALUES, true)) {
                return $ai;
            }
        }

        return 'other';
    }

    // -------------------------------------------------------------------------
    // 4. dedupKey / areSameProduct
    // -------------------------------------------------------------------------

    /**
     * Deterministic duplicate key: normalized product stem + rounded price.
     * Two rows with the identical key are the same offer (cover vs inner
     * page double-read, whitespace/punctuation noise, pack filler text).
     */
    public static function dedupKey(string $productName, float $salePrice): string
    {
        return self::normalizedStem($productName).'|'.number_format($salePrice, 2, '.', '');
    }

    /**
     * Same product? Identical dedupKey OR (equal sale price AND normalized
     * stems ≥ 82% similar).
     */
    public static function areSameProduct(FlyerItem $a, FlyerItem $b): bool
    {
        $keyA = self::dedupKey((string) $a->product_name, (float) $a->sale_price);
        $keyB = self::dedupKey((string) $b->product_name, (float) $b->sale_price);

        if ($keyA !== '' && $keyA === $keyB) {
            return true;
        }

        if (abs((float) $a->sale_price - (float) $b->sale_price) >= 0.005) {
            return false;
        }

        $stemA = self::normalizedStem((string) $a->product_name);
        $stemB = self::normalizedStem((string) $b->product_name);

        if ($stemA === '' || $stemB === '') {
            return false;
        }

        return self::similarityPercent($stemA, $stemB) >= self::STEM_SIMILARITY_THRESHOLD;
    }

    // -------------------------------------------------------------------------
    // Richest-Row Winner deduplication (shared by the batch finalizer and the
    // flyers:clean-items command — callers persist the results).
    // -------------------------------------------------------------------------

    /**
     * Group duplicates across `$items` (ORDERED BY id) and merge every
     * duplicate group onto its RICHEST row:
     *
     *  scoring: old_price +10, brand_id +5, unit +3 — ties break on the
     *  CLEANER name (shorter normalized stem, +2 equivalent) then on the
     *  earlier row id. Missing fields (old_price / brand_id / unit /
     *  category) are merged onto the survivor IN MEMORY.
     *
     * @param  iterable<FlyerItem>  $items  rows of ONE flyer, ordered by id
     * @return array{kept: list<FlyerItem>, removedIds: list<int>}
     */
    public static function deduplicate(iterable $items): array
    {
        $kept = [];
        $removedIds = [];

        foreach ($items as $item) {
            $matchIndex = null;

            foreach ($kept as $index => $survivor) {
                if (self::areSameProduct($survivor, $item)) {
                    $matchIndex = $index;

                    break;
                }
            }

            if ($matchIndex === null) {
                $kept[] = $item;

                continue;
            }

            $survivor = $kept[$matchIndex];

            // Richest row wins the survivorship.
            if (self::isRicherRow($item, $survivor)) {
                $dropped = $survivor;
                $survivor = $item;
                $kept[$matchIndex] = $item;
            } else {
                $dropped = $item;
            }

            self::mergeMissingFields($survivor, $dropped);
            $removedIds[] = (int) $dropped->id;
        }

        return ['kept' => $kept, 'removedIds' => $removedIds];
    }

    /**
     * Does `$candidate` beat `$current` as the surviving row?
     * Richer score first, then the cleaner (shorter) normalized stem.
     */
    private static function isRicherRow(FlyerItem $candidate, FlyerItem $current): bool
    {
        $candidateScore = self::rowScore($candidate);
        $currentScore = self::rowScore($current);

        if ($candidateScore !== $currentScore) {
            return $candidateScore > $currentScore;
        }

        $candidateStem = mb_strlen(self::normalizedStem((string) $candidate->product_name));
        $currentStem = mb_strlen(self::normalizedStem((string) $current->product_name));

        return $candidateStem < $currentStem;
    }

    /**
     * Richness score: old_price +10, brand_id +5, unit +3. The "+2 cleaner
     * name" weight is applied as the tie-break inside isRicherRow() (name
     * quality is only comparable BETWEEN rows, never absolute).
     */
    private static function rowScore(FlyerItem $item): int
    {
        $score = 0;

        if ($item->old_price !== null && (float) $item->old_price > 0) {
            $score += 10;
        }

        if ($item->brand_id !== null) {
            $score += 5;
        }

        if (trim((string) $item->unit) !== '') {
            $score += 3;
        }

        return $score;
    }

    /**
     * Merge every field missing on the survivor from the dropped row, then
     * recompute savings/discount if an old_price was merged.
     */
    private static function mergeMissingFields(FlyerItem $survivor, FlyerItem $dropped): void
    {
        if (($survivor->old_price === null || (float) $survivor->old_price <= 0)
            && $dropped->old_price !== null && (float) $dropped->old_price > 0) {
            $survivor->old_price = $dropped->old_price;
            self::recomputeDiscount($survivor);
        }

        if ($survivor->brand_id === null && $dropped->brand_id !== null) {
            $survivor->brand_id = $dropped->brand_id;
        }

        if (trim((string) $survivor->unit) === '' && trim((string) $dropped->unit) !== '') {
            $survivor->unit = $dropped->unit;
        }

        if (blank($survivor->category) && ! blank($dropped->category)) {
            $survivor->category = $dropped->category;
        }

        // Adopt the cleaner (shorter-stem) name when the survivor's read was noisier.
        $survivorStem = self::normalizedStem((string) $survivor->product_name);
        $droppedStem = self::normalizedStem((string) $dropped->product_name);

        if ($droppedStem !== '' && mb_strlen($droppedStem) < mb_strlen($survivorStem)
            && trim((string) $dropped->product_name) !== trim((string) $survivor->product_name)) {
            $survivor->product_name = $dropped->product_name;
        }
    }

    /**
     * Recompute savings_amount + discount_percent from sale/old price
     * (guards: old must exceed sale; >90% discounts are superscript misreads
     * and are nulled instead of published).
     */
    private static function recomputeDiscount(FlyerItem $item): void
    {
        $sale = (float) $item->sale_price;
        $old = (float) $item->old_price;

        if ($old <= 0 || $sale <= 0 || $old <= $sale) {
            $item->savings_amount = null;
            $item->discount_percent = null;

            return;
        }

        $discount = round((($old - $sale) / $old) * 100, 2);

        if ($discount > 90) {
            $item->savings_amount = null;
            $item->discount_percent = null;

            return;
        }

        $item->savings_amount = round($old - $sale, 2);
        $item->discount_percent = $discount;
    }

    // -------------------------------------------------------------------------
    // 5. extractStandardUnitPrice
    // -------------------------------------------------------------------------

    /**
     * "سعر الوحدة": parse quantity + physical unit out of the printed
     * product name ("5 لتر", "500 جم", "2.5 لتر", "9 كجم", "450 جم", "200 مل")
     * and compute the price per 1 كجم or 1 لتر. A printed pack of exactly
     * "1" ("1 لتر") is NOT re-quoted (the unit price would equal the sale
     * price) unless `$includeQuantityOne` — the Legacy caller (views) opts
     * in to keep the pre-existing column behaviour.
     *
     * @return array{amount: float, unit: string}|null
     */
    public static function extractStandardUnitPrice(
        string $productName,
        ?string $unit,
        float $salePrice,
        bool $includeQuantityOne = false,
    ): ?array {
        if ($salePrice <= 0) {
            return null;
        }

        // 1) Preferred source: quantity + unit printed INSIDE the name —
        //    first match wins (flyer names put the pack size first).
        $name = ArabicNormalizer::toWesternDigits(trim($productName));

        if (preg_match('/(\d+(?:\.\d+)?)\s*(كيلو|كجم|كغ|جم|جرام|لتر|ليتر|مل|ملي|م\s*ل)/u', $name, $m) === 1) {
            $qty = (float) str_replace(',', '.', $m[1]);
            $unitToken = trim($m[2]);

            if ($qty > 0) {
                $standard = self::standardize($qty, $unitToken);

                if ($standard !== null) {
                    [$standardQty, $standardUnit] = $standard;

                    if ($standardQty === 1.0 && ! $includeQuantityOne) {
                        return null;
                    }

                    return self::unitPrice($salePrice, $standardQty, $standardUnit);
                }
            }
        }

        // 2) Fallback: the OCR `unit` column alone ("كجم" → the price IS
        //    per kg; "جم"/"مل" → normalise to the standard unit; "قطعة" …
        //    → no comparable standard unit, stay null).
        return match (trim((string) $unit)) {
            'كجم', 'كيلو', 'كغ' => self::unitPrice($salePrice, 1.0, 'كجم'),
            'لتر', 'ليتر' => self::unitPrice($salePrice, 1.0, 'لتر'),
            'جم', 'جرام' => self::unitPrice($salePrice, 0.001, 'كجم'),
            'مل', 'ملي' => self::unitPrice($salePrice, 0.001, 'لتر'),
            default => null,
        };
    }

    /**
     * @return array{0: float, 1: string}|null [standardQuantity, standardUnit]
     */
    private static function standardize(float $qty, string $unitToken): ?array
    {
        if (in_array($unitToken, ['جم', 'جرام'], true)) {
            return [$qty / 1000, 'كجم'];
        }

        if (in_array($unitToken, ['كجم', 'كيلو', 'كغ'], true)) {
            return [$qty, 'كجم'];
        }

        if (in_array($unitToken, ['مل', 'ملي', 'م ل'], true)) {
            return [$qty / 1000, 'لتر'];
        }

        if (in_array($unitToken, ['لتر', 'ليتر'], true)) {
            return [$qty, 'لتر'];
        }

        return null;
    }

    /**
     * @return array{amount: float, unit: string}|null
     */
    private static function unitPrice(float $salePrice, float $standardQty, string $standardUnit): ?array
    {
        if ($standardQty <= 0) {
            return null;
        }

        return [
            'amount' => $salePrice / $standardQty,
            'unit' => $standardUnit,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Canonical dedup stem: cleaned name → Arabic-normalized → latin unit
     * tokens unified → filler phrases stripped → punctuation stripped.
     */
    public static function normalizedStem(string $productName): string
    {
        $stem = self::cleanProductName($productName);
        $stem = ArabicNormalizer::normalize($stem);
        $stem = mb_strtolower($stem);

        // Latin unit tokens → Arabic canonical ("5W-30" never matches here:
        // digits+letters mixes survive — only bare unit words are swapped).
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])kgs?(?![\p{L}\p{N}])/iu', 'كجم', $stem);
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])(?:gms?|gr)(?![\p{L}\p{N}])/iu', 'جم', $stem);
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])ltrs?(?![\p{L}\p{N}])/iu', 'لتر', $stem);
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])(?:liter|litre|lt)(?![\p{L}\p{N}])/iu', 'لتر', $stem);
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])mls?(?![\p{L}\p{N}])/iu', 'مل', $stem);
        $stem = (string) preg_replace('/(?<![\p{L}\p{N}])pcs?\.?(?![\p{L}\p{N}])/iu', 'قطعة', $stem);

        foreach (self::STEM_FILLERS as $filler) {
            $normalizedFiller = ArabicNormalizer::normalize($filler);
            $stem = (string) preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($normalizedFiller, '/').'(?![\p{L}\p{N}])/u',
                ' ',
                $stem,
            );
        }

        // Punctuation never carries dedup signal ("زيت، عافية" == "زيت عافية").
        $stem = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $stem);

        return trim((string) preg_replace('/\s+/u', ' ', $stem));
    }

    /**
     * UTF-8-safe similarity in the spirit of similar_text(): ASCII-only
     * stems use the built-in function verbatim; Arabic stems use an LCS
     * ratio (2·LCS / (lenA + lenB) × 100) — byte-based similar_text would
     * skew Arabic (2-byte) strings.
     */
    private static function similarityPercent(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $a.$b) !== 1) {
            similar_text($a, $b, $percent);

            return (float) $percent;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $charsA = mb_str_split($a);
        $charsB = mb_str_split($b);

        // O(n·m) LCS table — stems are short (<60 chars), memory is trivial.
        $table = array_fill(0, $lenA + 1, array_fill(0, $lenB + 1, 0));

        for ($i = $lenA - 1; $i >= 0; $i--) {
            for ($j = $lenB - 1; $j >= 0; $j--) {
                $table[$i][$j] = $charsA[$i] === $charsB[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        return (2 * $table[0][0] / ($lenA + $lenB)) * 100;
    }

    /**
     * @return array<string, string> wrong → right, word-boundary-safe
     */
    private static function productCorrections(): array
    {
        $corrections = [];

        foreach ((array) config('ocr_aliases.product_corrections', []) as $wrong => $right) {
            $wrong = trim((string) $wrong);
            $right = trim((string) $right);

            if ($wrong !== '' && $right !== '' && $wrong !== $right) {
                $corrections[$wrong] = $right;
            }
        }

        return $corrections;
    }

    /**
     * @return array<string, string> normalized raw key → canonical name
     */
    private static function brandAliases(): array
    {
        static $aliases = null;

        if ($aliases !== null) {
            return $aliases;
        }

        $aliases = [];

        foreach ((array) config('ocr_aliases.brand_aliases', []) as $raw => $canonical) {
            $raw = trim((string) $raw);
            $canonical = trim((string) $canonical);

            if ($raw === '' || $canonical === '') {
                continue;
            }

            $aliases[self::brandKey($raw)] = $canonical;
        }

        return $aliases;
    }

    /**
     * Normalized lookup key for a brand string.
     */
    private static function brandKey(string $brand): string
    {
        return mb_strtolower(trim(ArabicNormalizer::normalize(ArabicNormalizer::toWesternDigits($brand))));
    }

    /**
     * @return array<string, string> normalized Brand name → stored name
     */
    private static function cachedBrandKeys(): array
    {
        $names = Cache::remember(self::BRAND_CACHE_KEY, self::BRAND_CACHE_TTL, static function (): array {
            return Brand::query()->orderBy('id')->pluck('name')->all();
        });

        $keys = [];

        foreach ((array) $names as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                $keys[self::brandKey($name)] = $name;
            }
        }

        return $keys;
    }

    /**
     * Dynamic fuzzy fallback: normalized raw ≥ min length and mb-safe
     * Levenshtein ≤ max distance against an existing canonical Brand.
     */
    private static function fuzzySnapToBrand(string $key): ?string
    {
        $minLength = (int) config('ocr_aliases.fuzzy_brand.min_length', 5);
        $maxDistance = (int) config('ocr_aliases.fuzzy_brand.max_distance', 1);

        if (mb_strlen($key) < $minLength) {
            return null;
        }

        foreach (self::cachedBrandKeys() as $canonicalKey => $storedName) {
            if (self::mbLevenshtein($key, $canonicalKey) <= $maxDistance) {
                return $storedName;
            }
        }

        return null;
    }

    /**
     * @return list<string> known canonical brand names, longest first
     */
    private static function knownCanonicalBrands(): array
    {
        $names = array_values(array_unique(array_merge(
            array_values(self::cachedBrandKeys()),
            array_values(self::brandAliases()),
        )));

        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    /**
     * Word-boundary-safe containment — checked against the raw haystack AND
     * its Arabic-normalized form (hamza/ة/ى drift must not hide a brand).
     */
    private static function containsWord(string $haystack, string $needle): bool
    {
        $needle = trim($needle);

        if ($needle === '' || $haystack === '') {
            return false;
        }

        if (self::wordRegexMatch($haystack, $needle)) {
            return true;
        }

        $normHaystack = ArabicNormalizer::normalize($haystack);
        $normNeedle = ArabicNormalizer::normalize($needle);

        if ($normHaystack !== $haystack || $normNeedle !== $needle) {
            return self::wordRegexMatch($normHaystack, $normNeedle);
        }

        return false;
    }

    private static function wordRegexMatch(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u',
            $haystack,
        ) === 1;
    }

    /**
     * Multibyte-safe Levenshtein (PHP's levenshtein() is byte-based and
     * overcounts Arabic 2-byte characters). Names are short — O(n·m) is fine.
     */
    private static function mbLevenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $charsA = mb_str_split($a);
        $charsB = mb_str_split($b);

        $previous = range(0, $lenB);

        for ($i = 1; $i <= $lenA; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $lenB; $j++) {
                $cost = $charsA[$i - 1] === $charsB[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,      // deletion
                    $current[$j - 1] + 1,   // insertion
                    $previous[$j - 1] + $cost, // substitution
                );
            }

            $previous = $current;
        }

        return $previous[$lenB];
    }
}
