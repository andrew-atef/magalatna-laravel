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
 * GEO upgrade: "Top Active Deals Today" on retailer hubs (HTML + Markdown).
 * AI crawlers (Copilot, ChatGPT Search, Perplexity) extract item-level
 * prices straight from the hub instead of citing competitors — but the
 * table MUST only ever contain items from flyers that are truly live today
 * (Published + valid_from <= today <= valid_until): never Expired, never
 * Draft/PendingReview, never future-dated flyers.
 */
final class RetailerTopDealsTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    /**
     * Active retailer + one live-today flyer (trait defaults:
     * valid_from yesterday, valid_until +7 days).
     */
    private function createKazyonHub(): Flyer
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon', 'name' => 'كازيون'], [
            'slug' => 'kazyon-live-flyer',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        return $flyer;
    }

    public function test_html_hub_lists_top_active_items_with_prices_and_deep_links(): void
    {
        $flyer = $this->createKazyonHub();

        $top = $this->addItem($flyer, [
            'product_name' => 'زيت عباد الشمس الفاخر',
            'slug' => 'item-top-'.uniqid(),
            'sale_price' => 50.0,
            'old_price' => 80.0,
            'discount_percent' => 37.5,
            'unit' => '1.5 لتر',
        ]);
        $this->addItem($flyer, [
            'product_name' => 'أرز بسمتي عادي',
            'slug' => 'item-nodisc-'.uniqid(),
            'sale_price' => 30.0,
            'old_price' => null,
            'discount_percent' => null,
            'unit' => 'كيلو',
        ]);

        $response = $this->get('/kazyon');

        $response->assertOk();
        $response->assertSee('أبرز أسعار وعروض كازيون السارية اليوم');
        $response->assertSee('زيت عباد الشمس الفاخر');
        // Deep link to the item's own row anchor on the flyer page.
        $response->assertSee('#item-'.$top->id, false);
        $response->assertSee('50.00');
        $response->assertSee('-38%');
        $response->assertSee('أرز بسمتي عادي');
        $response->assertSee('1.5 لتر');
    }

    public function test_hub_excludes_expired_and_draft_flyer_items(): void
    {
        $flyer = $this->createKazyonHub();
        $this->addItem($flyer, [
            'product_name' => 'سلعة سارية مميزة',
            'slug' => 'live-'.uniqid(),
            'sale_price' => 10.0,
            'old_price' => 20.0,
            'discount_percent' => 50.0,
        ]);

        $expired = Flyer::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'title' => 'مجلة منتهية',
            'slug' => 'kazyon-expired-flyer',
            'valid_from' => now('Africa/Cairo')->subDays(20)->toDateString(),
            'valid_until' => now('Africa/Cairo')->subDays(10)->toDateString(),
            'status' => FlyerStatus::Expired,
            'total_pages' => 1,
        ]);
        $this->addItem($expired, [
            'product_name' => 'سلعة منتهية لا تظهر',
            'slug' => 'expired-'.uniqid(),
            'sale_price' => 5.0,
            'old_price' => 9.0,
            'discount_percent' => 44.44,
        ]);

        $draft = Flyer::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'title' => 'مجلة مسودة',
            'slug' => 'kazyon-draft-flyer',
            'valid_from' => now('Africa/Cairo')->subDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'status' => FlyerStatus::Draft,
            'total_pages' => 1,
        ]);
        $this->addItem($draft, [
            'product_name' => 'سلعة مسودة لا تظهر',
            'slug' => 'draft-'.uniqid(),
            'sale_price' => 3.0,
            'old_price' => 6.0,
            'discount_percent' => 50.0,
        ]);

        $html = $this->get('/kazyon');
        $html->assertOk();
        $html->assertSee('سلعة سارية مميزة');
        $html->assertDontSee('سلعة منتهية لا تظهر');
        $html->assertDontSee('سلعة مسودة لا تظهر');

        $markdown = $this->get('/kazyon', ['Accept' => 'text/markdown']);
        $markdown->assertOk();
        $markdown->assertSee('سلعة سارية مميزة');
        $markdown->assertDontSee('سلعة منتهية لا تظهر');
        $markdown->assertDontSee('سلعة مسودة لا تظهر');
    }

    public function test_markdown_hub_lists_item_table_with_canonical_deep_links(): void
    {
        $flyer = $this->createKazyonHub();
        $item = $this->addItem($flyer, [
            'product_name' => 'جبنة رومي مضاعفة',
            'slug' => 'cheese-'.uniqid(),
            'sale_price' => 120.5,
            'old_price' => 150.0,
            'discount_percent' => 19.67,
            'unit' => 'كيلو',
        ]);

        $response = $this->get('/kazyon', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $response->assertSee('## أبرز أسعار وعروض كازيون السارية اليوم');
        $response->assertSee('| اسم السلعة | الوحدة | سعر العرض (ج.م) | السعر القديم (ج.م) | نسبة التوفير |', false);
        // Canonical config host + item row anchor — AI crawlers get a
        // resolvable absolute URL, never a request-host or relative leak.
        $response->assertSee('https://magalatna.com/offers/kazyon-live-flyer#item-'.$item->id, false);
        $response->assertSee('120.50');
        $response->assertSee('150.00');
        $response->assertSee('20%');
    }

    public function test_future_dated_flyer_items_never_appear_in_today_table(): void
    {
        $flyer = $this->createKazyonHub();
        $this->addItem($flyer, [
            'product_name' => 'سلعة سارية مميزة',
            'slug' => 'live-'.uniqid(),
            'sale_price' => 10.0,
            'old_price' => 20.0,
            'discount_percent' => 50.0,
        ]);

        // Published but starts TOMORROW — its items must not be labeled
        // "السارية اليوم" even though status/valid_until pass the filter.
        $future = Flyer::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'title' => 'مجلة تبدأ غداً',
            'slug' => 'kazyon-future-flyer',
            'valid_from' => now('Africa/Cairo')->addDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(10)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);
        $this->addItem($future, [
            'product_name' => 'سلعة مستقبلية لا تظهر',
            'slug' => 'future-'.uniqid(),
            'sale_price' => 1.0,
            'old_price' => 99.0,
            'discount_percent' => 98.99,
        ]);

        $html = $this->get('/kazyon');
        $html->assertOk();
        $html->assertSee('سلعة سارية مميزة');
        $html->assertDontSee('سلعة مستقبلية لا تظهر');
    }

    public function test_retailer_without_active_flyers_has_no_top_deals_section(): void
    {
        Retailer::query()->create([
            'name' => 'جملا ماركت',
            'slug' => 'gomlamarket',
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ]);

        $this->get('/gomlamarket')->assertOk()->assertDontSee('أبرز أسعار وعروض');

        $markdown = $this->get('/gomlamarket', ['Accept' => 'text/markdown']);
        $markdown->assertOk();
        $markdown->assertDontSee('أبرز أسعار وعروض');
    }
}
