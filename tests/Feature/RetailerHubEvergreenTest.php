<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Evergreen Retailer Hubs upgrade: E-E-A-T data (hotline/branches/schedule),
 * anti thin-content about copy, FAQ smart defaults + FAQPage schema,
 * diversified top deals across MULTIPLE flyers, freshness lastmod.
 */
final class RetailerHubEvergreenTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    private function createHubRetailer(array $retailerAttributes = []): Retailer
    {
        $retailer = $this->createPublishedFlyer(array_merge([
            'slug' => 'kazyon',
            'name' => 'كازيون',
        ], $retailerAttributes), ['slug' => 'kazyon-live-flyer']);
        $this->addPage($retailer, 'flyers/pages/p1.webp');

        return $retailer->retailer;
    }

    public function test_faqs_list_smart_defaults_quote_schedule_and_hotline(): void
    {
        $retailer = $this->createHubRetailer([
            'hotline' => '19609',
            'branches_count' => 450,
            'schedule_summary' => 'مجلة كازيون تصدر صباح كل ثلاثاء.',
        ]);

        $faqs = $retailer->faqs_list;

        $this->assertCount(3, $faqs);
        $this->assertSame('متى تنزل عروض كازيون الجديدة في مصر؟', $faqs[0]['question']);
        $this->assertStringContainsString('مجلة كازيون تصدر صباح كل ثلاثاء.', $faqs[0]['answer']);
        $this->assertStringContainsString('19609', $faqs[1]['answer']);
        $this->assertStringContainsString('فروع الجمهورية', $faqs[2]['answer']);
    }

    public function test_curated_faqs_win_over_smart_defaults(): void
    {
        $retailer = $this->createHubRetailer([
            'faqs' => [['question' => 'سؤال مخصص؟', 'answer' => 'إجابة مخصصة.']],
        ]);

        $faqs = $retailer->faqs_list;

        $this->assertCount(1, $faqs);
        $this->assertSame('سؤال مخصص؟', $faqs[0]['question']);
        $this->assertSame('إجابة مخصصة.', $faqs[0]['answer']);
    }

    public function test_faqs_list_without_hotline_uses_generic_contact_answer(): void
    {
        $retailer = $this->createHubRetailer();

        $faqs = $retailer->faqs_list;

        $this->assertStringContainsString('الصفحة الرسمية للمتجر', $faqs[1]['answer']);
        $this->assertStringNotContainsString('19609', $faqs[1]['answer']);
    }

    public function test_effective_lastmod_uses_latest_published_flyer_update(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon', 'name' => 'كازيون'], ['slug' => 'kazyon-live-flyer']);
        $retailer = $flyer->retailer;

        // Timestamps OFF so saveQuietly() does NOT silently bump updated_at
        // back to now() (that would erase the controlled fixture date).
        $retailer->timestamps = false;
        $flyer->timestamps = false;

        // Flyer updated 5 days ago, retailer row updated 3 days ago →
        // the RETAILER's own date wins (it is the newer of the two).
        $flyer->updated_at = now('Africa/Cairo')->subDays(5)->startOfSecond();
        $flyer->saveQuietly();
        $retailer->updated_at = now('Africa/Cairo')->subDays(3)->startOfSecond();
        $retailer->saveQuietly();

        $this->assertSame(
            $retailer->updated_at->format('Y-m-d H:i:s'),
            $retailer->refresh()->effective_lastmod->format('Y-m-d H:i:s'),
        );

        // Push the flyer's updated_at ahead → hub freshness follows it.
        $flyer->updated_at = now('Africa/Cairo')->addDays(1)->startOfSecond();
        $flyer->saveQuietly();

        $this->assertSame(
            $flyer->updated_at->format('Y-m-d H:i:s'),
            $retailer->refresh()->effective_lastmod->format('Y-m-d H:i:s'),
        );
    }

    public function test_top_deals_draw_from_multiple_flyers_with_per_flyer_cap(): void
    {
        $retailer = $this->createHubRetailer();

        $flyerA = Flyer::query()->where('slug', 'kazyon-live-flyer')->firstOrFail();
        // Flyer A: 5 discounted oils — only the TOP 4 may surface.
        foreach ([50.0, 49.0, 48.0, 47.0, 46.0] as $i => $discount) {
            $this->addItem($flyerA, [
                'product_name' => 'زيت عافية رقم '.($i + 1),
                'slug' => 'oil-'.uniqid(),
                'sale_price' => 10.0 + $i,
                'old_price' => 20.0,
                'discount_percent' => $discount,
                'category' => 'oils_ghee',
            ]);
        }

        // Flyer B (also live today): one dairy deal — must surface TOO.
        $flyerB = $this->createPublishedFlyer([], ['slug' => 'kazyon-second-flyer', 'retailer_id' => $retailer->id]);
        $this->addPage($flyerB, 'flyers/pages/p2.webp');
        $dairy = $this->addItem($flyerB, [
            'product_name' => 'جبنة رومي بيضاء',
            'slug' => 'dairy-'.uniqid(),
            'sale_price' => 80.0,
            'old_price' => 100.0,
            'discount_percent' => 20.0,
            'category' => 'dairy_cheese',
        ]);

        $html = $this->get('/kazyon');

        $html->assertOk();
        // Both flyers contribute to the diversified table.
        $html->assertSee('زيت عافية رقم 1');
        $html->assertSee('جبنة رومي بيضاء');
        // Flyer A's 5th item (46%) never makes the per-flyer top-4 cut.
        $html->assertDontSee('زيت عافية رقم 5');
        // New accountability columns render.
        $html->assertSee('المجلة / العرض');
        $html->assertSee('تاريخ الانتهاء');
        $html->assertSee('#item-'.$dairy->id, false);
    }

    public function test_hub_renders_eeat_data_faq_section_and_faqpage_schema(): void
    {
        $retailer = $this->createHubRetailer([
            'hotline' => '19609',
            'branches_count' => 450,
            'schedule_summary' => 'مجلة كازيون تصدر صباح كل ثلاثاء.',
            'about_text' => '<p>سلسلة كازيون من أسرع سلاسل التجزئة نمواً في مصر.</p>',
        ]);

        $html = $this->get('/kazyon');
        $content = (string) $html->getContent();

        $html->assertOk();
        // E-E-A-T hero data.
        $html->assertSee('الخط الساخن');
        $html->assertSee('19609');
        $html->assertSee('450+ فرع بمصر');
        $html->assertSee('مواعيد نزول العروض');
        $html->assertSee('مجلة كازيون تصدر صباح كل ثلاثاء.');
        // Anti thin-content about + FAQ section.
        $html->assertSee('سلسلة كازيون من أسرع سلاسل التجزئة نمواً في مصر.');
        $html->assertSee('الأسئلة الشائعة عن عروض كازيون');
        $html->assertSee('متى تنزل عروض كازيون الجديدة في مصر؟');
        // FAQPage schema node (JSON_UNESCAPED_UNICODE keeps Arabic literal).
        $this->assertStringContainsString('"FAQPage"', $content);
        $this->assertStringContainsString('متى تنزل عروض كازيون الجديدة في مصر؟', $content);
        // Breadcrumb points at the config home URL WITH trailing slash.
        $this->assertStringContainsString('<a href="https://magalatna.com/"', $content);
        // Title stays inside the 56-char pixel-safe window (layout appends
        // the site suffix itself).
        preg_match('/<title>(.*?)<\/title>/u', $content, $m);
        $this->assertNotEmpty($m);
        $this->assertLessThanOrEqual(56, mb_strlen($m[1]));
    }

    public function test_expired_flyer_cards_are_nofollow(): void
    {
        $retailer = $this->createHubRetailer();

        $expired = Flyer::query()->create([
            'retailer_id' => $retailer->id,
            'title' => 'مجلة كازيون المنتهية',
            'slug' => 'kazyon-expired-nofollow',
            'valid_from' => now('Africa/Cairo')->subDays(10)->toDateString(),
            'valid_until' => now('Africa/Cairo')->subDays(4)->toDateString(),
            'status' => FlyerStatus::Expired,
            'total_pages' => 1,
        ]);
        $this->addPage($expired, 'flyers/pages/old.webp');

        $content = (string) $this->get('/kazyon')->getContent();

        // Host-agnostic: route() renders with the REQUEST host in tests —
        // the nofollow attribute is what matters.
        $this->assertStringContainsString('offers/kazyon-expired-nofollow" rel="nofollow"', $content);
    }
}
