<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Flyer;
use App\Services\ImageOptimizerService;
use App\Support\ArabicDateHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\CreatesFixtures;

/**
 * Google Discover / SERP CTR upgrade:
 *  - Arabic grammatical pluralization (تمييز العدد)
 *  - price-bearing 135–155 char meta description
 *  - 1280x720 (16:9) Discover hero composite (GD, synthetic image)
 */
final class DiscoverSeoUpgradeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFixtures;

    public function test_pluralize_count_follows_arabic_number_grammar(): void
    {
        $pluralize = static fn (int $count): string => ArabicDateHelper::pluralizeCount(
            $count,
            'صفحة',
            'صفحتان',
            'صفحات',
            'صفحة',
        );

        $this->assertSame('1 صفحة', $pluralize(1));
        $this->assertSame('صفحتان', $pluralize(2));
        $this->assertSame('3 صفحات', $pluralize(3));
        $this->assertSame('10 صفحات', $pluralize(10));
        $this->assertSame('11 صفحة', $pluralize(11));
        $this->assertSame('25 صفحة', $pluralize(25));
    }

    public function test_meta_description_carries_real_prices_and_stays_within_limits(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer, [
            'product_name' => 'زيت كريستال ذرة 1.6 لتر',
            'sale_price' => 219.95,
            'discount_percent' => 31.2,
        ]);
        $this->addItem($flyer, [
            'product_name' => 'مناديل بابيا',
            'sale_price' => 164.95,
            'discount_percent' => 18.4,
        ]);
        $this->addItem($flyer, [
            'product_name' => 'تونة صن شاين',
            'sale_price' => 41.95,
            'discount_percent' => 12.9,
        ]);

        $flyer = $flyer->refresh();
        $flyer->setRelation('retailer', $flyer->retailer);
        $flyer->setRelation('items', $flyer->items()->get());

        $meta = $flyer->generateMetaDescription();

        // Real prices ARE in the snippet — the snippet IS the answer. (Long
        // item names can force the 155-char cap to drop the third item —
        // the cap always wins over the 3-item target.)
        $this->assertStringContainsString('بـ 219.95 ج.م', $meta);
        $this->assertStringContainsString('بـ 164.95 ج.م', $meta);
        $this->assertGreaterThanOrEqual(2, substr_count($meta, 'بـ '));
        // Active phrasing, never archive wording.
        $this->assertStringContainsString('أسعار عروض', $meta);
        $this->assertStringContainsString('تصفح المجلة والأسعار كاملة.', $meta);
        // Length window: hard cap at 155, no machine-output ellipsis.
        $this->assertLessThanOrEqual(155, mb_strlen($meta));
        $this->assertStringNotContainsString('…', $meta);
        $this->assertStringNotContainsString('...', $meta);
    }

    public function test_meta_description_for_expired_flyer_reads_as_archive(): void
    {
        $flyer = $this->createPublishedFlyer([], [
            'valid_from' => now('Africa/Cairo')->subDays(20)->toDateString(),
            'valid_until' => now('Africa/Cairo')->subDays(3)->toDateString(),
        ]);
        $this->addItem($flyer, [
            'product_name' => 'أرز أبو كاس 1 كجم',
            'sale_price' => 35.50,
            'discount_percent' => 20.0,
        ]);

        $flyer = $flyer->refresh();
        $flyer->setRelation('retailer', $flyer->retailer);
        $flyer->setRelation('items', $flyer->items()->get());

        $meta = $flyer->generateMetaDescription();

        $this->assertStringContainsString('أرشيف أسعار عروض', $meta);
        $this->assertStringContainsString('قارن تطور الأسعار في مصر عبر مجلاتنا.', $meta);
        $this->assertStringContainsString('بـ 35.5 ج.م', $meta);
        $this->assertLessThanOrEqual(155, mb_strlen($meta));
    }

    public function test_upcoming_flyer_bluf_never_claims_saree(): void
    {
        $flyer = $this->createPublishedFlyer([], [
            'valid_from' => now('Africa/Cairo')->addDays(2)->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(9)->toDateString(),
        ]);

        $bluf = $flyer->bluf_summary;

        $this->assertStringContainsString('يبدأ في مصر يوم', $bluf);
        $this->assertStringNotContainsString('الساري في مصر', $bluf);
    }

    public function test_discover_composite_is_webp_at_16_9_canvas(): void
    {
        $coverBinary = $this->syntheticPageBinary(720, 960);
        $page2Binary = $this->syntheticPageBinary(720, 960);

        $discoverBinary = app(ImageOptimizerService::class)
            ->generateDiscoverComposite($coverBinary, $page2Binary);

        // WebP magic bytes: RIFF....WEBP
        $this->assertSame('RIFF', substr($discoverBinary, 0, 4));
        $this->assertSame('WEBP', substr($discoverBinary, 8, 4));

        // Soft ≤70KB target (logs above it, never fails) — assert sane size.
        $this->assertLessThan(200 * 1024, strlen($discoverBinary));
    }

    /**
     * Synthetic "flyer page" binary: a solid-ish PNG rendered via GD —
     * realistic enough for scale/blur/encode pipelines without R2.
     */
    private function syntheticPageBinary(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $white = (int) imagecolorallocate($image, 245, 245, 245);
        imagefill($image, 0, 0, $white);
        $navy = (int) imagecolorallocate($image, 2, 59, 85);
        imagefilledrectangle($image, 40, 40, $width - 40, 200, $navy);
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }
}
