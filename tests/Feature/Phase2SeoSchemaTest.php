<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Support\R2Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class Phase2SeoSchemaTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_expired_flyer_renders_noindex_and_is_excluded_from_sitemap(): void
    {
        $expired = $this->createPublishedFlyer([], [
            'slug' => 'expired-archive-offer',
            'status' => FlyerStatus::Expired,
            'valid_until' => now('Africa/Cairo')->subDays(3)->toDateString(),
        ]);
        $this->addPage($expired, 'flyers/pages/expired.webp');

        $live = $this->createPublishedFlyer([], ['slug' => 'live-current-offer']);
        $this->addPage($live, 'flyers/pages/live.webp');

        $html = $this->get('/offers/expired-archive-offer')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);

        Cache::forget('sitemap_xml_content');
        $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        // Expired pages render noindex,follow → they must NEVER be listed
        // ("Submitted URL marked noindex" otherwise).
        $this->assertStringNotContainsString('expired-archive-offer', $sitemap);
        $this->assertStringContainsString('live-current-offer', $sitemap);
        // Canonical host only — no www leak, no request-host leak.
        $this->assertStringNotContainsString('www.magalatna.com', $sitemap);
        $this->assertStringContainsString('https://magalatna.com/', $sitemap);
    }

    public function test_published_flyer_uses_fixed_image_preview_directive(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'preview-directive-offer']);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $html = $this->get('/offers/preview-directive-offer')->assertOk()->getContent();

        $this->assertStringContainsString('max-image-preview:large', $html);
        $this->assertStringNotContainsString('max-image-size:large', $html);
    }

    public function test_show_json_ld_parses_with_all_items_strikethrough_and_no_fake_brand(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'schema-graph-offer', 'total_pages' => 1]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        // 60 discounted items — MORE than the old take(50) truncation — all
        // WITHOUT a brand, plus one zero-priced item that must be excluded.
        for ($i = 0; $i < 60; $i++) {
            $this->addItem($flyer, [
                'product_name' => 'منتج اختبار رقم '.$i,
                'slug' => 'schema-item-'.$i.'-'.uniqid(),
                'sale_price' => 10 + $i,
                'old_price' => 20 + $i,
                'discount_percent' => 50.0,
            ]);
        }
        $this->addItem($flyer, [
            'product_name' => 'سلعة بدون سعر',
            'slug' => 'zero-priced-'.uniqid(),
            'sale_price' => 0,
            'old_price' => null,
            'discount_percent' => null,
        ]);

        $content = (string) $this->get('/offers/schema-graph-offer')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $content, $m));
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $types = [];
        $itemList = null;
        $event = null;
        $collectionPage = null;
        $organization = null;
        $specialAnnouncement = null;

        foreach ($decoded['@graph'] as $node) {
            $types[] = $node['@type'];

            if ($node['@type'] === 'ItemList') {
                $itemList = $node;
            }

            if ($node['@type'] === 'SaleEvent') {
                $event = $node;
            }

            if ($node['@type'] === 'CollectionPage') {
                $collectionPage = $node;
            }

            if ($node['@type'] === 'SpecialAnnouncement') {
                $specialAnnouncement = $node;
            }

            if ($node['@type'] === 'Organization') {
                $organization = $node;
            }
        }

        $this->assertNotNull($itemList);
        $this->assertNotNull($event);
        $this->assertNotNull($collectionPage);
        $this->assertNotNull($organization);
        // SpecialAnnouncement is RETIRED by Google — it must be gone entirely.
        $this->assertNull($specialAnnouncement);

        // itemListElement MUST match numberOfItems — no take(50) truncation,
        // zero-priced items excluded.
        $this->assertSame(60, $itemList['numberOfItems']);
        $this->assertCount(60, $itemList['itemListElement']);

        // StrikethroughPrice (NOT ListPrice) on the struck-through old price.
        $firstProduct = $itemList['itemListElement'][0]['item'];
        $this->assertSame(
            'https://schema.org/StrikethroughPrice',
            $firstProduct['offers']['priceSpecification']['priceType'],
        );

        // Brand hygiene: no brand set → NO fake Brand fallback to product name.
        foreach ($itemList['itemListElement'] as $element) {
            $this->assertArrayNotHasKey('brand', $element['item']);
        }

        // Full schema.org URIs for eventStatus, EventCompleted (not Cancelled).
        $this->assertSame('https://schema.org/EventScheduled', $event['eventStatus']);

        // Cairo ISO-8601: midnight start of valid_from, end-of-day valid_until.
        $cairoFrom = now('Africa/Cairo')->subDay()->toDateString();
        $cairoUntil = now('Africa/Cairo')->addDays(7)->toDateString();
        $this->assertMatchesRegularExpression(
            '/^'.preg_quote($cairoFrom, '/').'T00:00:00(\.0+)?\+0[23]:00$/',
            (string) $event['startDate'],
        );
        $this->assertMatchesRegularExpression(
            '/^'.preg_quote($cairoUntil, '/').'T23:59:59(\.\d+)?\+0[23]:00$/',
            (string) $event['endDate'],
        );

        // CollectionPage carries the full linkage web: isPartOf (#website),
        // breadcrumb (#breadcrumb), publisher (#organization), mainEntity
        // (#itemlist) and both publication timestamps.
        $this->assertSame($collectionPage['isPartOf']['@id'], 'https://magalatna.com/#website');
        $this->assertSame($collectionPage['breadcrumb']['@id'], 'https://magalatna.com/offers/schema-graph-offer#breadcrumb');
        $this->assertSame($collectionPage['publisher']['@id'], $organization['@id']);
        $this->assertSame($collectionPage['mainEntity']['@id'], 'https://magalatna.com/offers/schema-graph-offer#itemlist');
        $this->assertNotEmpty($collectionPage['datePublished']);
        $this->assertNotEmpty($collectionPage['dateModified']);

        // SaleEvent organizer = the RETAILER running the sale (never the
        // aggregator site), plus Organization sameAs hygiene:
        // empty social config → the sameAs key is OMITTED entirely
        // ("sameAs": [] is flagged as an invalid property by Rich Results).
        $this->assertSame($flyer->retailer->name, $event['organizer']['name']);
        $this->assertStringStartsWith('https://magalatna.com/', $event['organizer']['url']);
        $this->assertArrayNotHasKey('sameAs', $organization);
    }

    public function test_show_json_ld_omits_fake_brand_when_brand_exists(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'brand-offer', 'total_pages' => 1]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');
        $this->addItem($flyer, [
            'product_name' => 'زيت حقيقي',
            'slug' => 'brand-item-'.uniqid(),
            'sale_price' => 90.0,
            'old_price' => 120.0,
            'discount_percent' => 25.0,
        ]);

        $content = (string) $this->get('/offers/brand-offer')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $content, $m));
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $itemList = null;
        foreach ($decoded['@graph'] as $node) {
            if ($node['@type'] === 'ItemList') {
                $itemList = $node;
            }
        }

        $this->assertNotNull($itemList);
        $firstProduct = $itemList['itemListElement'][0]['item'];
        $this->assertArrayNotHasKey('brand', $firstProduct);
    }

    public function test_show_json_ld_emits_same_as_image_and_valid_from_only_with_real_data(): void
    {
        // Non-empty (with a blank entry to prove filtering) social config →
        // sameAs IS emitted with exactly the real profiles.
        config(['app.social_links' => ['https://facebook.com/magalatna', '', 'https://x.com/magalatna']]);

        $flyer = $this->createPublishedFlyer([], ['slug' => 'sameas-offer', 'total_pages' => 1]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');
        $this->addItem($flyer, [
            'product_name' => 'زيت عباد الشمس',
            'slug' => 'sameas-item-'.uniqid(),
            'sale_price' => 50.0,
            'old_price' => 80.0,
            'discount_percent' => 37.5,
        ]);

        $content = (string) $this->get('/offers/sameas-offer')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $content, $m));
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $organization = null;
        $event = null;
        $itemList = null;
        foreach ($decoded['@graph'] as $node) {
            if ($node['@type'] === 'Organization') {
                $organization = $node;
            }
            if ($node['@type'] === 'SaleEvent') {
                $event = $node;
            }
            if ($node['@type'] === 'ItemList') {
                $itemList = $node;
            }
        }

        $this->assertNotNull($organization);
        $this->assertNotNull($event);
        $this->assertNotNull($itemList);

        // Real profiles only — blank entries filtered out, no empty array.
        $this->assertSame(
            ['https://facebook.com/magalatna', 'https://x.com/magalatna'],
            $organization['sameAs'],
        );

        // SaleEvent carries the real cover image (Event "image" notice).
        $this->assertNotEmpty($event['image']);

        // Offer validFrom mirrors the Cairo-anchored SaleEvent startDate.
        $firstOffer = $itemList['itemListElement'][0]['item']['offers'];
        $this->assertSame($event['startDate'], $firstOffer['validFrom']);

        // Product description is real data (name + retailer + unit), not filler.
        $this->assertStringContainsString('زيت عباد الشمس', $itemList['itemListElement'][0]['item']['description']);
    }

    public function test_resized_asset_never_emits_cdn_cgi_when_disabled(): void
    {
        config([
            'filesystems.disks.r2.url' => 'https://cdn.magalatna.com',
            'filesystems.disks.r2.image_resizing_enabled' => false,
        ]);

        // Free Plan default: plain original asset — mobile browsers can NEVER
        // receive a 403/404 broken /cdn-cgi/image/ URL.
        $this->assertSame(
            'https://cdn.magalatna.com/flyers/pages/p1.webp',
            R2Url::resizedAsset('flyers/pages/p1.webp', 600),
        );

        config(['filesystems.disks.r2.image_resizing_enabled' => true]);

        $this->assertStringContainsString(
            '/cdn-cgi/image/width=600,format=webp/',
            (string) R2Url::resizedAsset('flyers/pages/p1.webp', 600),
        );
    }
}
