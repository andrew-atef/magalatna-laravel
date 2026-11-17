<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Support\CairoTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Restored from tests/Feature/Phase2SeoUpdatesTest.php — that exact FILENAME
 * is being blocked by something on this machine (creation denied + the file
 * kept vanishing), so it lives under a new name. Content is identical.
 */
final class Phase2SeoSearchRankingTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);

        // These fixtures create MANY flyers → FlyerObserver fires purge jobs
        // per save. Under QUEUE_CONNECTION=sync that is a REAL Cloudflare API
        // call per fixture (local .env carries prod tokens) — ~10 rapid calls
        // rate-limit-stall the test process. Blank the credentials so the
        // jobs short-circuit with zero network I/O.
        config([
            'services.cloudflare.api_token' => null,
            'services.cloudflare.zone_id' => null,
            'services.indexnow.key' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Word-boundary search ranking
    // ------------------------------------------------------------------

    public function test_word_search_ranks_oils_first_and_excludes_olive_and_motor_oil(): void
    {
        $flyer = $this->createPublishedFlyer();

        $this->addItem($flyer, [
            'product_name' => 'زيت طهي عافية 1.5 لتر',
            'category' => 'oils_ghee',
        ]);
        $this->addItem($flyer, [
            'product_name' => 'بديل زيت للجسم من فيشي',
            'category' => 'personal_care',
        ]);
        $this->addItem($flyer, [
            'product_name' => 'زيتون أخضر مخلي',
            'category' => 'groceries',
        ]);
        $this->addItem($flyer, [
            'product_name' => 'زيت محرك ليكوي مولي 5w-30',
            'category' => 'automotive_tools',
        ]);

        $response = $this->get('/?q=زيت');

        $response->assertOk();

        $items = $response->viewData('matchingItems')->getCollection();
        $names = $items->pluck('product_name');

        // Cooking oil surfaces — and NEVER the olive luncheon or motor oil.
        $this->assertTrue($names->contains(fn ($n) => str_contains((string) $n, 'زيت طهي')));
        $this->assertFalse($names->contains(fn ($n) => str_contains((string) $n, 'زيتون')));
        $this->assertFalse($names->contains(fn ($n) => str_contains((string) $n, 'ليكوي')));

        // Grocery oils rank ABOVE personal care ("بديل زيت").
        $this->assertSame(
            'زيت طهي عافية 1.5 لتر',
            $items->first()->product_name,
        );
    }

    public function test_auto_query_surfaces_motor_oil(): void
    {
        $flyer = $this->createPublishedFlyer();

        $this->addItem($flyer, [
            'product_name' => 'زيت محرك ليكوي مولي 5w-30',
            'category' => 'automotive_tools',
        ]);
        $this->addItem($flyer, [
            'product_name' => 'زيت طهي عافية 1.5 لتر',
            'category' => 'oils_ghee',
        ]);

        $response = $this->get('/?q='.rawurlencode('زيت محرك'));

        $response->assertOk();

        $names = $response->viewData('matchingItems')->getCollection()->pluck('product_name');
        $this->assertTrue($names->contains(fn ($n) => str_contains((string) $n, 'ليكوي')));
    }

    public function test_substring_fallback_runs_when_word_boundary_matches_nothing(): void
    {
        $flyer = $this->createPublishedFlyer();

        // "بسي" is a fragment of "بيبسي" — NOT a word boundary hit, so the
        // whole-word path must return zero and the substring fallback kicks in.
        $this->addItem($flyer, ['product_name' => 'بيبسي كانز 330 مل']);

        $response = $this->get('/?q=بسي');

        $response->assertOk();

        $names = $response->viewData('matchingItems')->getCollection()->pluck('product_name');
        $this->assertTrue($names->contains(fn ($n) => str_contains((string) $n, 'بيبسي')));
    }

    // ------------------------------------------------------------------
    // 2. ?q= robots directives + pagination nofollow
    // ------------------------------------------------------------------

    public function test_search_page_is_noindex_without_canonical_and_nofollow_links(): void
    {
        $flyer = $this->createPublishedFlyer();

        // 13 matching rows (same name, distinct prices → dedup keeps all)
        // so the items paginator renders a page-2 link.
        foreach (range(1, 13) as $i) {
            $this->addItem($flyer, [
                'product_name' => 'أرز مصري فاخر 1 كجم',
                'sale_price' => 20.0 + $i,
            ]);
        }

        $response = $this->get('/?q=أرز');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');

        $html = (string) $response->getContent();
        // A noindex search page must NEVER canonical to "/".
        $this->assertStringNotContainsString('rel="canonical"', $html);
        // Every pagination <a> on a ?q= page is nofollow.
        $this->assertStringContainsString('items_page=2', $html);
        $this->assertStringContainsString('rel="nofollow"', $html);
    }

    public function test_paginated_clean_page_keeps_self_canonical(): void
    {
        // 13 flyers → the main grid paginates to page 2.
        foreach (range(1, 13) as $i) {
            $this->createPublishedFlyer([], ['slug' => 'live-flyer-'.$i]);
        }

        $response = $this->get('/?page=2');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('rel="canonical" href="https://magalatna.com/?page=2"', $html);
        $this->assertStringNotContainsString('rel="nofollow"', $html);
    }

    // ------------------------------------------------------------------
    // 3. Expired flyer schema + copy
    // ------------------------------------------------------------------

    public function test_expired_flyer_has_no_sale_event_and_archive_copy(): void
    {
        $expired = $this->createPublishedFlyer([], [
            'slug' => 'expired-archive-schema',
            'status' => FlyerStatus::Expired,
            'valid_until' => now('Africa/Cairo')->subDays(3)->toDateString(),
        ]);
        $this->addPage($expired, 'flyers/pages/expired.webp');
        $this->addItem($expired, ['product_name' => 'جبنة رومي 1 كجم', 'category' => 'dairy_cheese']);
        $this->addItem($expired, ['product_name' => 'زيت عافية 1.5 لتر']);

        $response = $this->get('/offers/expired-archive-schema');

        $response->assertOk();

        $html = (string) $response->getContent();

        // NEVER EventCompleted (invalid enum) — and NO SaleEvent at all.
        $this->assertStringNotContainsString('EventCompleted', $html);
        $this->assertStringNotContainsString('SaleEvent', $html);

        // Archive availability + honest copy.
        $this->assertStringContainsString('https://schema.org/Discontinued', $html);
        $this->assertStringContainsString('أرشيف سعر سابق في', $html);
        $this->assertStringContainsString('انتهت فترة سريان', $html);
        $this->assertStringContainsString('[أرشيف]', $html);
        $this->assertStringContainsString('أرشيف تاريخي لمقارنة الأسعار في مصر', $html);

        // Stale editorial copy must not render on expired flyers.
        $this->assertStringNotContainsString('نظرة سريعة على العرض', $html);
        $this->assertStringNotContainsString('عرض سارٍ في', $html);
    }

    public function test_live_flyer_still_emits_sale_event_and_instock(): void
    {
        $live = $this->createPublishedFlyer([], ['slug' => 'live-schema-check']);
        $this->addPage($live, 'flyers/pages/live.webp');
        $this->addItem($live, ['product_name' => 'زيت عافية 1.5 لتر']);

        $response = $this->get('/offers/live-schema-check');

        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('SaleEvent', $html);
        $this->assertStringContainsString('EventScheduled', $html);
        $this->assertStringContainsString('https://schema.org/InStock', $html);
    }

    // ------------------------------------------------------------------
    // 4. Sitemap retailer lastmod
    // ------------------------------------------------------------------

    public function test_sitemap_retailer_lastmod_uses_latest_published_flyer(): void
    {
        Cache::forget('sitemap_xml_content');

        $flyer = $this->createPublishedFlyer([], ['slug' => 'fresh-flyer-for-lastmod']);

        // Push the flyer's updated_at a full day ahead of the retailer row.
        $flyer->updated_at = now('Africa/Cairo')->addDay();
        $flyer->saveQuietly();

        $response = $this->get('/sitemap.xml');

        $response->assertOk();

        $xml = (string) $response->getContent();
        $expected = CairoTime::toIso8601($flyer->updated_at->format('Y-m-d H:i:s'));

        $this->assertStringContainsString($expected, $xml);
    }

    // ------------------------------------------------------------------
    // 5. llms.txt completeness
    // ------------------------------------------------------------------

    public function test_llms_txt_lists_active_flyers_with_counts_and_discount(): void
    {
        Cache::forget('llms_txt_content');

        $first = $this->createPublishedFlyer(['name' => 'كازيون مصر'], ['slug' => 'llms-flyer-one', 'title' => 'مجلة كازيون الأسبوعية']);
        $second = $this->createPublishedFlyer(['name' => 'هايبر وان'], ['slug' => 'llms-flyer-two', 'title' => 'مجلة هايبر وان الأسبوعية']);

        $this->addItem($first, ['product_name' => 'زيت عافية 1.5 لتر']);
        $this->addItem($first, ['product_name' => 'أرز مصري 1 كجم']);
        $this->addItem($second, ['product_name' => 'جبنة رومي 1 كجم']);

        $response = $this->get('/llms.txt');

        $response->assertOk();

        $txt = (string) $response->getContent();

        $this->assertStringContainsString('مجلة كازيون الأسبوعية', $txt);
        $this->assertStringContainsString('مجلة هايبر وان الأسبوعية', $txt);
        // Items count + rounded max discount on every flyer line.
        $this->assertStringContainsString('2 سلعة مُوثَّقة بجدول أسعار كامل', $txt);
        $this->assertStringContainsString('أقوى خصم 25%', $txt);
    }

    // ------------------------------------------------------------------
    // 6. Mobile drawer completeness
    // ------------------------------------------------------------------

    public function test_mobile_drawer_lists_all_active_retailers_including_hyperone(): void
    {
        Cache::forget('layout:header_retailers');

        $this->createPublishedFlyer(['name' => 'هايبر وان', 'slug' => 'hyperone']);
        $this->createPublishedFlyer(['name' => 'بيم مصر', 'slug' => 'bimmisr']);

        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('id="mobile-drawer"', $html);
        $this->assertStringContainsString('هايبر وان', $html);
        $this->assertStringContainsString('بيم مصر', $html);
    }
}
