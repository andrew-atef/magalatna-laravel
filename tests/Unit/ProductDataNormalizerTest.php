<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FlyerItem;
use App\Support\ProductDataNormalizer;
use PHPUnit\Framework\TestCase;

final class ProductDataNormalizerTest extends TestCase
{
    public function test_unit_price_from_name_parses_litres_grams_and_kilos(): void
    {
        // "زيت عافية 1.5 لتر" @ 90.50 EGP → 60.33 EGP / لتر
        $litres = ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'زيت عافية 1.5 لتر',
            'sale_price' => 90.50,
        ]));
        $this->assertSame('لتر', $litres['unit']);
        $this->assertEqualsWithDelta(60.33, $litres['amount'], 0.01);

        // "جبنة رومي 500 جم" @ 100 EGP → 200 EGP / كجم
        $grams = ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'جبنة رومي 500 جم',
            'sale_price' => 100.0,
        ]));
        $this->assertSame('كجم', $grams['unit']);
        $this->assertEqualsWithDelta(200.0, $grams['amount'], 0.01);

        // Arabic-Indic digits: "٥٠٠ جم" @ 60 → 120 EGP / كجم
        $arabicDigits = ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'أرز مصري ٥٠٠ جم',
            'sale_price' => 60.0,
        ]));
        $this->assertSame('كجم', $arabicDigits['unit']);
        $this->assertEqualsWithDelta(120.0, $arabicDigits['amount'], 0.01);

        // Bare "كجم" unit column: the price IS per kg.
        $bareUnit = ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'سكر أبيض',
            'sale_price' => 42.0,
            'unit' => 'كجم',
        ]));
        $this->assertSame('كجم', $bareUnit['unit']);
        $this->assertEqualsWithDelta(42.0, $bareUnit['amount'], 0.01);
    }

    public function test_unit_price_returns_null_without_parsable_quantity(): void
    {
        $this->assertNull(ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'شيبسي حبة',
            'sale_price' => 10.0,
            'unit' => 'قطعة',
        ])));

        $this->assertNull(ProductDataNormalizer::extractStandardUnitPrice(new FlyerItem([
            'product_name' => 'سلعة مجانية',
            'sale_price' => 0,
            'unit' => 'كجم',
        ])));
    }

    public function test_search_query_uses_first_two_meaningful_words_and_brand(): void
    {
        // Weight tokens excluded; brand already inside the words → not duplicated.
        $this->assertSame(
            'زيت كريستال',
            ProductDataNormalizer::searchQuery('زيت كريستال 1 لتر', 'كريستال'),
        );

        // Longer descriptive names truncate to the product family.
        $this->assertSame(
            'حليب المراعي',
            ProductDataNormalizer::searchQuery('حليب المراعي كامل الدسم 1 لتر', 'المراعي'),
        );

        // No brand at all.
        $this->assertSame(
            'أرز مصري',
            ProductDataNormalizer::searchQuery('أرز مصري 5 كجم'),
        );

        // Pure-number/pack tokens are dropped entirely.
        $this->assertSame(
            'تونة رأس',
            ProductDataNormalizer::searchQuery('تونة 3 رأس', null),
        );
    }

    public function test_classify_product_buckets_are_mutually_exclusive(): void
    {
        $this->assertSame('oil', ProductDataNormalizer::classifyProduct('زيت عباد الشمس 1 لتر'));
        $this->assertSame('oil', ProductDataNormalizer::classifyProduct('سمن نباتي جبنا'));
        $this->assertSame('dairy', ProductDataNormalizer::classifyProduct('جبنة رومي 500 جم'));
        $this->assertSame('dairy', ProductDataNormalizer::classifyProduct('حليب جهينة كامل الدسم'));
        $this->assertSame('meat', ProductDataNormalizer::classifyProduct('فراخ بانيه مجمدة'));
        $this->assertSame('clean', ProductDataNormalizer::classifyProduct('مسحوق غسيل بريل'));
        $this->assertSame('grocery', ProductDataNormalizer::classifyProduct('أرز مصري 5 كجم'));
        $this->assertSame('grocery', ProductDataNormalizer::classifyProduct('شاي ليبتون 100 كيس'));
    }

    public function test_meta_excerpt_is_word_safe_and_capped(): void
    {
        $short = 'جملة قصيرة سارية حتى 30 نوفمبر.';
        $this->assertSame($short, ProductDataNormalizer::metaExcerpt($short, 155));

        $long = implode(' ', array_fill(0, 60, 'كلمة')); // 60 × "كلمة" + spaces
        $cut = ProductDataNormalizer::metaExcerpt($long, 155);
        $this->assertLessThanOrEqual(156, mb_strlen($cut));
        $this->assertStringEndsWith('…', $cut);
        // The LAST word must be complete — never cut mid-word.
        $this->assertSame('كلمة…', mb_substr($cut, -5));
    }
}
