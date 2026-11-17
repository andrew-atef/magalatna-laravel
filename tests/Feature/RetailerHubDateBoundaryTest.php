<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Regression guard for the Nov-17 kazyon scenario: 2 active-today flyers
 * (16-17 + 17-23 weeklies) + 2 upcoming. Locks in the Cairo date-boundary
 * logic (a flyer valid TODAY is never "upcoming"), the diversified
 * both-weeklies top-deals table, pluralized Arabic badges, the Discover
 * og:image with explicit 1280x720 dimensions, and the smart about fallback.
 */
final class RetailerHubDateBoundaryTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_kazyon_nov17_scenario(): void
    {
        $retailer = $this->createPublishedFlyer([            'slug' => 'kazyon',
            'name' => 'كازيون (Kazyon)',
        ], [
            'slug' => 'kazyon-16-17-nov',
            'valid_from' => now('Africa/Cairo')->toDateString(),
            'valid_until' => now('Africa/Cairo')->toDateString(),
            'title' => 'مجلة كازيون 16-17 نوفمبر',
            'total_pages' => 3,
        ]);
        $this->addPage($retailer, 'flyers/pages/p1.webp', 1);
        $this->addPage($retailer, 'flyers/pages/p2.webp', 2);
        $this->addPage($retailer, 'flyers/pages/p3.webp', 3);

        $weekly = Flyer::query()->create([
            'retailer_id' => $retailer->retailer_id,
            'title' => 'مجلة كازيون 17-23 نوفمبر',
            'slug' => 'kazyon-17-23-nov',
            'valid_from' => now('Africa/Cairo')->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(6)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 25,
            'discover_image_path' => 'flyers/discover/kazyon-17-23.webp',
        ]);
        $this->addPage($weekly, 'flyers/pages/w1.webp');
        for ($p = 2; $p <= 25; $p++) {
            $this->addPage($weekly, 'flyers/pages/w'.$p.'.webp', $p);
        }

        $upcoming1 = Flyer::query()->create([
            'retailer_id' => $retailer->retailer_id,
            'title' => 'مجلة كازيون القادمة 1',
            'slug' => 'kazyon-upcoming-1',
            'valid_from' => now('Africa/Cairo')->addDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(8)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);
        Flyer::query()->create([
            'retailer_id' => $retailer->retailer_id,
            'title' => 'مجلة كازيون القادمة 2',
            'slug' => 'kazyon-upcoming-2',
            'valid_from' => now('Africa/Cairo')->addDays(2)->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(9)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);

        $this->addItem($retailer, [
            'product_name' => 'زيت عافية من مجلة 16-17',
            'slug' => 'item-16-17-'.uniqid(),
            'sale_price' => 40.0,
            'old_price' => 80.0,
            'discount_percent' => 50.0,
        ]);
        $this->addItem($weekly, [
            'product_name' => 'سكر من مجلة 17-23',
            'slug' => 'item-17-23-'.uniqid(),
            'sale_price' => 30.0,
            'old_price' => 60.0,
            'discount_percent' => 50.0,
        ]);

        $response = $this->get('/kazyon');
        $response->assertOk();
        $content = (string) $response->getContent();

        // 1. Hero badges — dual form implies the count of 2 grammatically.
        $this->assertStringContainsString('مجلتان ساريتان اليوم', $content);
        $this->assertStringContainsString('⏳ مجلتان تبدآن قريباً', $content);

        // 2. Pluralized page counts (3 pages / 25 pages).
        $this->assertStringContainsString('3 صفحات', $content);
        $this->assertStringContainsString('25 صفحة', $content);

        // 3. og:image is the Discover image of the longest-living flyer + exact dimensions.
        $this->assertStringContainsString('kazyon-17-23.webp', $content);
        $this->assertStringContainsString('<meta property="og:image:width" content="1280">', $content);
        $this->assertStringContainsString('<meta property="og:image:height" content="720">', $content);

        // 4. About fallback (about_text is empty on this fixture retailer).
        $this->assertStringContainsString('عن عروض وفروع', $content);
        $this->assertStringContainsString('تعتبر سلسلة', $content);
        $this->assertStringNotContainsString('بشبكة فروع', $content); // branches_count=0 → phrase omitted
        $this->assertStringContainsString('تسهيل المقارنة والتوفير الذكي', $content);
        // No blank gap: fallback card renders directly above the FAQ block.
        $this->assertStringContainsString('الأسئلة الشائعة عن عروض', $content);

        // 5. Top deals table draws from BOTH weeklies.
        $this->assertStringContainsString('زيت عافية من مجلة 16-17', $content);
        $this->assertStringContainsString('سكر من مجلة 17-23', $content);
        $this->assertStringContainsString('مجلة كازيون 16-17 نوفمبر', $content);
        $this->assertStringContainsString('مجلة كازيون 17-23 نوفمبر', $content);

        // 6. Upcoming flyers' ITEMS never leak into the "live today" table.
        $upcomingFlyer = Flyer::query()->where('slug', 'kazyon-upcoming-1')->firstOrFail();
        $this->addItem($upcomingFlyer, [
            'product_name' => 'سلعة قادمة لا تظهر',
            'slug' => 'item-upcoming-'.uniqid(),
            'sale_price' => 1.0,
            'old_price' => 99.0,
            'discount_percent' => 98.99,
        ]);
        $content2 = (string) $this->get('/kazyon')->getContent();
        $this->assertStringNotContainsString('سلعة قادمة لا تظهر', $content2);

        // 7. Expired archive badge wording.
        $expired = Flyer::query()->create([
            'retailer_id' => $retailer->retailer_id,
            'title' => 'مجلة كازيون منتهية',
            'slug' => 'kazyon-expired-1',
            'valid_from' => now('Africa/Cairo')->subDays(6)->toDateString(),
            'valid_until' => now('Africa/Cairo')->subDays(2)->toDateString(),
            'status' => FlyerStatus::Expired,
            'total_pages' => 1,
        ]);
        $this->addPage($expired, 'flyers/pages/e1.webp');
        $content3 = (string) $this->get('/kazyon')->getContent();
        $this->assertStringContainsString('مجلة للسجل السعري', $content3);

        // 8. Sitemap lastmod follows today's flyer updates (not a frozen date).
        $sitemapLastmod = $retailer->retailer->refresh()->effective_lastmod->toDateString();
        $this->assertSame(now('Africa/Cairo')->toDateString(), $sitemapLastmod);
    }
}
