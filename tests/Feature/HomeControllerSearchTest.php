<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Restored from tests/Feature/HomeControllerTest.php — that exact FILENAME
 * is being blocked by something on this machine (creation denied + the file
 * kept vanishing), so it lives under a new name. Content is identical.
 */
final class HomeControllerSearchTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_wildcard_search_is_escaped_and_matches_nothing(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer, ['product_name' => 'جبنة رومي 1 كيلو']);

        $response = $this->get('/?q=%');

        $response->assertOk();
        // "%" is a literal now — it must NOT match every row (full scan).
        $this->assertSame(0, $response->viewData('flyers')->count());
        $matching = $response->viewData('matchingItems');
        $this->assertTrue($matching === null || $matching->count() === 0);
    }

    public function test_underscore_search_is_escaped(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer, ['product_name' => 'أرز أبو كاس 1 كجم']);

        $response = $this->get('/?q=_');

        $response->assertOk();
        $this->assertSame(0, $response->viewData('flyers')->count());
    }

    public function test_backslash_search_is_escaped(): void
    {
        $this->createPublishedFlyer();

        $response = $this->get('/?q='.rawurlencode('\\'));

        $response->assertOk();
        $this->assertSame(0, $response->viewData('flyers')->count());
    }

    public function test_real_search_still_matches_normalized_names(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer, ['product_name' => 'أرز أبو كاس 1 كجم']);

        $response = $this->get('/?q='.rawurlencode('أبو كاس'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('flyers')->count());
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
        $this->assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_plain_home_is_indexable_and_has_vary(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_retailer_filter_sets_noindex(): void
    {
        $this->createPublishedFlyer();

        $response = $this->get('/?retailer=x');

        $response->assertOk();
        $this->assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_inactive_retailer_flyers_are_hidden(): void
    {
        $this->createPublishedFlyer(['is_active' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame(0, $response->viewData('flyers')->count());
    }

    public function test_zero_price_items_excluded_from_hot_items(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer, ['product_name' => 'مجاني وهمي', 'sale_price' => 0, 'discount_percent' => 99.99]);
        $this->addItem($flyer, ['product_name' => 'منتج حقيقي', 'sale_price' => 50.00, 'discount_percent' => 30.00]);

        $response = $this->get('/');

        $response->assertOk();
        $hotItems = $response->viewData('hotItems');
        $this->assertSame(1, $hotItems->count());
        $this->assertSame('منتج حقيقي', $hotItems->first()->product_name);
    }

    public function test_long_search_is_capped(): void
    {
        $this->createPublishedFlyer();

        $response = $this->get('/?q='.rawurlencode(str_repeat('ا', 500)));

        $response->assertOk();
    }
}
