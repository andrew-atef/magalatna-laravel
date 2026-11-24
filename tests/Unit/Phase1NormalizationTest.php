<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FlyerItem;
use App\Services\ProductDataNormalizer;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 1 post-OCR normalization pipeline — deterministic unit coverage.
 *
 * The brand cache is SEEDED directly (cachedBrandKeys() reads
 * Cache::remember('auto_linker:brands')) so no database is needed: this is
 * a pure pipeline test, exactly like production runs with a warm cache.
 */
final class Phase1NormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('auto_linker:brands', ['كابوكسينو', 'عافية'], 60);
    }

    // ---------------------------------------------------------------------
    // ArabicNormalizer::toWesternDigits
    // ---------------------------------------------------------------------

    public function test_to_western_digits_converts_eastern_and_persian_digits(): void
    {
        $this->assertSame('150', ArabicNormalizer::toWesternDigits('١٥٠'));
        $this->assertSame('5', ArabicNormalizer::toWesternDigits('٥'));
        $this->assertSame('5', ArabicNormalizer::toWesternDigits('۵'));
        $this->assertSame('0123456789', ArabicNormalizer::toWesternDigits('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('9 كجم', ArabicNormalizer::toWesternDigits('٩ كجم'));
    }

    public function test_to_western_digits_fixes_decimal_comma_but_keeps_thousands(): void
    {
        $this->assertSame('1.5 لتر', ArabicNormalizer::toWesternDigits('١,٥ لتر'));
        $this->assertSame('2.25 كجم', ArabicNormalizer::toWesternDigits('٢,٢٥ كجم'));
        // Thousands separators must survive untouched.
        $this->assertSame('1,200', ArabicNormalizer::toWesternDigits('1,200'));
        $this->assertSame('12,500 ج.م', ArabicNormalizer::toWesternDigits('12,500 ج.م'));
    }

    // ---------------------------------------------------------------------
    // cleanProductName
    // ---------------------------------------------------------------------

    public function test_clean_product_name_fixes_ocr_letter_for_digit_units(): void
    {
        $this->assertSame('1 كجم أرز', ProductDataNormalizer::cleanProductName('ا كجم أرز'));
        $this->assertSame('أرز 1 كجم', ProductDataNormalizer::cleanProductName('أرز ا كجم'));
        $this->assertSame('رز 5 كجم', ProductDataNormalizer::cleanProductName('رز 5 ك'));
        $this->assertSame('بيبسي 3 قطعة', ProductDataNormalizer::cleanProductName('بيبسي 3 ق'));
    }

    public function test_clean_product_name_applies_canonical_typo_corrections(): void
    {
        $this->assertSame('دولفين صابون', ProductDataNormalizer::cleanProductName('دولتين صابون'));
        $this->assertSame('شامبو إلفيف', ProductDataNormalizer::cleanProductName('شامبو إفيف'));
        $this->assertSame('شامبو إلفيف', ProductDataNormalizer::cleanProductName('شامبو إلنيف'));
        $this->assertSame('عصير جبنتي', ProductDataNormalizer::cleanProductName('عصير جينتي'));
        $this->assertSame('بيبسي كانز', ProductDataNormalizer::cleanProductName('بيبيسي كانز'));
    }

    public function test_clean_product_name_combines_digit_and_typo_fixes(): void
    {
        $this->assertSame('زيت خليط 1.5 لتر', ProductDataNormalizer::cleanProductName('زيت خليط ١,٥ لتر'));
        $this->assertSame('دولفين 400 مل', ProductDataNormalizer::cleanProductName('دولتين ٤٠٠ مل'));
    }

    // ---------------------------------------------------------------------
    // inferCategory
    // ---------------------------------------------------------------------

    public function test_infer_category_motor_oil_wins_over_cooking_oil(): void
    {
        $this->assertSame('automotive_tools', ProductDataNormalizer::inferCategory('زيت محرك 5W-30'));
        $this->assertSame('automotive_tools', ProductDataNormalizer::inferCategory('ليكوي مولي موتور'));
        $this->assertSame('automotive_tools', ProductDataNormalizer::inferCategory('زيت محرك شل هليكس'));
    }

    public function test_infer_category_cooking_oil_is_oils_ghee(): void
    {
        $this->assertSame('oils_ghee', ProductDataNormalizer::inferCategory('زيت خليط 5 لتر'));
        $this->assertSame('oils_ghee', ProductDataNormalizer::inferCategory('زيت عباد الشمس 1 لتر'));
        $this->assertSame('oils_ghee', ProductDataNormalizer::inferCategory('سمنة نباتية كريستال'));
    }

    public function test_infer_category_hair_oil_substitute_is_personal_care(): void
    {
        $this->assertSame('personal_care', ProductDataNormalizer::inferCategory('بديل زيت إلفيف'));
        $this->assertSame('personal_care', ProductDataNormalizer::inferCategory('شامبو سوفي 400 مل'));
        $this->assertSame('personal_care', ProductDataNormalizer::inferCategory('مزيل عرق أكس'));
    }

    public function test_infer_category_cheese_brand_is_dairy_not_gum(): void
    {
        // جبنتي is an EGYPTIAN CHEESE brand (contains "جبن") — it must land
        // in dairy_cheese, never in the old gum-in-beverages rule. A leading
        // beverage keyword ("عصير") still wins the ordered scan by design.
        $this->assertSame('dairy_cheese', ProductDataNormalizer::inferCategory('جبنتي علكة'));
        $this->assertSame('dairy_cheese', ProductDataNormalizer::inferCategory('جبنة رومي 1 كجم'));
        $this->assertSame('beverages_snacks', ProductDataNormalizer::inferCategory('عصير برتقال'));
    }

    public function test_infer_category_all_major_groups(): void
    {
        $this->assertSame('dairy_cheese', ProductDataNormalizer::inferCategory('حليب جهينة 1 لتر'));
        $this->assertSame('meat_poultry', ProductDataNormalizer::inferCategory('بانيه دجاج مجمد'));
        $this->assertSame('groceries', ProductDataNormalizer::inferCategory('أرز مصري 5 كجم'));
        $this->assertSame('cleaning_laundry', ProductDataNormalizer::inferCategory('مسحوق برسيل 3 كجم'));
        $this->assertSame('home_appliances', ProductDataNormalizer::inferCategory('طقم طهي جرانيت'));
    }

    public function test_infer_category_ai_is_only_a_validated_fallback(): void
    {
        // No keyword match + valid AI answer → AI wins.
        $this->assertSame('meat_poultry', ProductDataNormalizer::inferCategory('منتج صغير', 'meat_poultry'));
        // No keyword match + INVALID AI answer → 'other' (closed vocabulary).
        $this->assertSame('other', ProductDataNormalizer::inferCategory('منتج صغير', 'فضائح'));
        // Nothing at all → 'other'.
        $this->assertSame('other', ProductDataNormalizer::inferCategory('منتج صغير'));
    }

    // ---------------------------------------------------------------------
    // canonicalBrandName
    // ---------------------------------------------------------------------

    public function test_canonical_brand_maps_ocr_aliases(): void
    {
        $this->assertSame('إلفيف', ProductDataNormalizer::canonicalBrandName('إفيف', 'شامبو إفيف'));
        $this->assertSame('إلفيف', ProductDataNormalizer::canonicalBrandName('Elvive', 'شامبو إلفيف'));
        $this->assertSame('بيبسي', ProductDataNormalizer::canonicalBrandName('بيبيسي', 'بيبسي كانز'));
        $this->assertSame('جبنتي', ProductDataNormalizer::canonicalBrandName('جينتي', 'علكة جينتي'));
    }

    public function test_canonical_brand_fuzzy_snaps_to_existing_brand(): void
    {
        // 'كابوسينو' is 1 mb-Levenshtein away from the cached Brand 'كابوكسينو'
        // (both ≥ 5 chars after normalization) → snap to the canonical row.
        $this->assertSame('كابوكسينو', ProductDataNormalizer::canonicalBrandName('كابوسينو', 'قهوة كابوسينو'));
    }

    public function test_canonical_brand_short_strings_never_fuzzy_snap(): void
    {
        // 'رز' is below the min length (5) — no snap, passes through raw.
        $this->assertSame('رز', ProductDataNormalizer::canonicalBrandName('رز', 'أرز مصري'));
    }

    public function test_canonical_brand_scans_name_when_raw_is_empty(): void
    {
        $this->assertSame('عافية', ProductDataNormalizer::canonicalBrandName(null, 'زيت عافية 1 لتر'));
        $this->assertSame('كابوكسينو', ProductDataNormalizer::canonicalBrandName('', 'قهوة كابوكسينو 250 جم'));
    }

    public function test_canonical_brand_returns_null_without_any_signal(): void
    {
        $this->assertNull(ProductDataNormalizer::canonicalBrandName(null, 'منتج عادي'));
        $this->assertNull(ProductDataNormalizer::canonicalBrandName('', 'منتج عادي'));
    }

    // ---------------------------------------------------------------------
    // dedupKey / areSameProduct
    // ---------------------------------------------------------------------

    private function item(string $name, float $price, int $id = 0): FlyerItem
    {
        $item = new FlyerItem([
            'product_name' => $name,
            'sale_price' => $price,
        ]);
        $item->id = $id;

        return $item;
    }

    public function test_are_same_product_identical_after_normalization(): void
    {
        $a = $this->item('زيت عافية  1.5 لتر', 90.50, 1);
        $b = $this->item('زيت عافية 1.5 لتر', 90.50, 2);

        $this->assertTrue(ProductDataNormalizer::areSameProduct($a, $b));
        $this->assertSame(ProductDataNormalizer::dedupKey('زيت عافية 1.5 لتر', 90.50), ProductDataNormalizer::dedupKey('زيت عافية  1.5 لتر', 90.50));
    }

    public function test_are_same_product_strip_pack_filler_variants(): void
    {
        $a = $this->item('مسحوق برسيل 3 كجم عادي أو حار', 120.00, 1);
        $b = $this->item('مسحوق برسيل 3 كجم', 120.00, 2);

        $this->assertTrue(ProductDataNormalizer::areSameProduct($a, $b));
    }

    public function test_are_same_product_fuzzy_stem_at_equal_price(): void
    {
        // One-letter OCR drift ('سوبر' vs 'سوبير') at the SAME price —
        // normalized stems are ≥ 82% similar → same product.
        $a = $this->item('زيت كريستال سوبر 5 لتر', 113.99, 1);
        $b = $this->item('زيت كريستال سوبير 5 لتر', 113.99, 2);

        $this->assertTrue(ProductDataNormalizer::areSameProduct($a, $b));
    }

    public function test_different_products_never_match(): void
    {
        // Different prices.
        $this->assertFalse(ProductDataNormalizer::areSameProduct(
            $this->item('زيت عافية 1.5 لتر', 90.50, 1),
            $this->item('زيت عافية 1.5 لتر', 95.00, 2),
        ));

        // Same price, unrelated stems.
        $this->assertFalse(ProductDataNormalizer::areSameProduct(
            $this->item('شامبو إكس 400 مل', 20.00, 1),
            $this->item('معجون سيجنال 100 مل', 20.00, 2),
        ));
    }

    // ---------------------------------------------------------------------
    // Richest-Row Winner deduplication
    // ---------------------------------------------------------------------

    public function test_deduplicate_richest_row_wins_and_merge_is_pure(): void
    {
        $poor = $this->item('زيت عافية 1.5 لتر', 90.50, 1);
        $rich = $this->item('زيت عافية 1.5 لتر', 90.50, 2);
        $rich->old_price = 120.00;
        $rich->unit = 'لتر';

        $result = ProductDataNormalizer::deduplicate([$poor, $rich]);

        $this->assertCount(1, $result['kept']);
        $this->assertSame([1], $result['removedIds']);
        $this->assertSame(2, $result['kept'][0]->id); // richer row survived
        $this->assertEquals(120.00, (float) $result['kept'][0]->old_price);
        $this->assertSame('لتر', (string) $result['kept'][0]->unit);
        // Merge is PURE — nothing was persisted by deduplicate() itself.
        $this->assertFalse($rich->wasChanged());
    }

    public function test_deduplicate_first_row_wins_richness_tie(): void
    {
        $first = $this->item('زيت عافية 1.5 لتر', 90.50, 1);
        $second = $this->item('زيت عافية 1.5 لتر', 90.50, 2);
        $second->old_price = 120.00;

        $result = ProductDataNormalizer::deduplicate([$first, $second]);

        // The RICHER second row wins survivorship; the poorer first row is
        // dropped (and had nothing to merge).
        $this->assertCount(1, $result['kept']);
        $this->assertSame([1], $result['removedIds']);
        $this->assertSame(2, $result['kept'][0]->id);
        $this->assertEquals(120.00, (float) $result['kept'][0]->old_price);
    }

    public function test_deduplicate_cleaner_name_tiebreak(): void
    {
        // Equal richness (no old_price anywhere) — the CLEANER (shorter-stem)
        // name wins survivorship over the noisier OCR read at the same price.
        $noisy = $this->item('زيت عافية طازج 1.5 لتر', 90.50, 1);
        $clean = $this->item('زيت عافية 1.5 لتر', 90.50, 2);

        $result = ProductDataNormalizer::deduplicate([$noisy, $clean]);

        $this->assertSame([1], $result['removedIds']);
        $this->assertCount(1, $result['kept']);
        $this->assertSame(2, $result['kept'][0]->id);
        $this->assertSame('زيت عافية 1.5 لتر', (string) $result['kept'][0]->product_name);
    }

    public function test_deduplicate_unique_items_pass_through(): void
    {
        $a = $this->item('زيت خليط 5 لتر', 113.99, 1);
        $b = $this->item('أرز مصري 5 كجم', 120.00, 2);

        $result = ProductDataNormalizer::deduplicate([$a, $b]);

        $this->assertCount(2, $result['kept']);
        $this->assertSame([], $result['removedIds']);
    }

    // ---------------------------------------------------------------------
    // extractStandardUnitPrice (service contract, qty != 1 rule)
    // ---------------------------------------------------------------------

    public function test_unit_price_computed_only_when_quantity_is_not_one(): void
    {
        // 5 لتر @ 113.99 → 22.798 / لتر
        $litres = ProductDataNormalizer::extractStandardUnitPrice('زيت كريستال 5 لتر', null, 113.99);
        $this->assertNotNull($litres);
        $this->assertSame('لتر', $litres['unit']);
        $this->assertEqualsWithDelta(22.80, $litres['amount'], 0.01);

        // 500 جم @ 100 → 200 / كجم
        $grams = ProductDataNormalizer::extractStandardUnitPrice('جبنة رومي 500 جم', null, 100.0);
        $this->assertNotNull($grams);
        $this->assertSame('كجم', $grams['unit']);
        $this->assertEqualsWithDelta(200.0, $grams['amount'], 0.01);

        // 2.5 لتر @ 90.50 → 36.2 / لتر
        $fractional = ProductDataNormalizer::extractStandardUnitPrice('زيت عافية 2.5 لتر', null, 90.50);
        $this->assertEqualsWithDelta(36.20, (float) $fractional['amount'], 0.01);
    }

    public function test_unit_price_safe_count_phrases(): void
    {
        // "100 فتلة" must NOT multiply the 250 g quantity.
        $tea = ProductDataNormalizer::extractStandardUnitPrice('شاي ليبتون 100 فتلة 250 جم', null, 100.0);
        $this->assertNotNull($tea);
        $this->assertEqualsWithDelta(400.0, $tea['amount'], 0.01);

        // "3 في 1" must NOT multiply the 18 g quantity.
        $coffee = ProductDataNormalizer::extractStandardUnitPrice('أبو عوف 3 في 1 18 جم', null, 10.0);
        $this->assertNotNull($coffee);
        $this->assertEqualsWithDelta(555.56, $coffee['amount'], 0.01);

        // Multi-term still sums to 30 L.
        $water = ProductDataNormalizer::extractStandardUnitPrice('12 مياة نستلة 1.5 لتر + 20 مياة 600 ملل', null, 106.99);
        $this->assertNotNull($water);
        $this->assertEqualsWithDelta(3.57, $water['amount'], 0.01);
    }

    public function test_unit_price_null_for_quantity_one_unless_legacy_opt_in(): void
    {
        // Pipeline rule: a printed pack of exactly "1" is NOT re-quoted.
        $this->assertNull(ProductDataNormalizer::extractStandardUnitPrice('زيت كريستال 1 لتر', null, 90.0));

        // Legacy view contract: qty == 1 still renders when opted in.
        $legacy = ProductDataNormalizer::extractStandardUnitPrice('زيت كريستال 1 لتر', null, 90.0, true);
        $this->assertNotNull($legacy);
        $this->assertSame('لتر', $legacy['unit']);
        $this->assertEqualsWithDelta(90.0, $legacy['amount'], 0.001);
    }

    public function test_unit_price_falls_back_to_unit_column(): void
    {
        $kg = ProductDataNormalizer::extractStandardUnitPrice('منتج خام', 'كجم', 50.0);
        $this->assertSame('كجم', $kg['unit']);
        $this->assertEqualsWithDelta(50.0, $kg['amount'], 0.001);

        $grams = ProductDataNormalizer::extractStandardUnitPrice('منتج خام', 'جم', 50.0);
        $this->assertNull($grams);

        // "قطعة" has no comparable standard unit.
        $this->assertNull(ProductDataNormalizer::extractStandardUnitPrice('منتج خام', 'قطعة', 50.0));
        $this->assertNull(ProductDataNormalizer::extractStandardUnitPrice('منتج خام', null, 50.0));
    }

    // ---------------------------------------------------------------------
    // Vocabulary integrity
    // ---------------------------------------------------------------------

    public function test_category_vocabulary_is_the_closed_ten_value_set(): void
    {
        $this->assertSame([
            'oils_ghee',
            'dairy_cheese',
            'meat_poultry',
            'groceries',
            'beverages_snacks',
            'apparel_clothing',
            'cleaning_laundry',
            'personal_care',
            'home_appliances',
            'automotive_tools',
            'other',
        ], ProductDataNormalizer::CATEGORY_VALUES);
    }
}
