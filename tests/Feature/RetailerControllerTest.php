<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class RetailerControllerTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_canonical_uses_configured_app_url(): void
    {
        $retailerSlug = 'kazyon-live';
        $this->createPublishedFlyer(['slug' => $retailerSlug]);

        $response = $this->get("/{$retailerSlug}");

        $response->assertOk();
        $canonical = $response->viewData('canonical');
        $this->assertSame('https://magalatna.com/'.$retailerSlug, $canonical);
    }

    public function test_inactive_retailer_404s(): void
    {
        $this->createPublishedFlyer(['is_active' => false, 'slug' => 'hidden-hub']);

        $this->get('/hidden-hub')->assertNotFound();
    }

    public function test_manually_expired_flyer_with_future_valid_until_appears_in_archive(): void
    {
        $retailerSlug = 'kazyon-live';
        /** @var Flyer $flyer */
        $flyer = $this->createPublishedFlyer(['slug' => $retailerSlug], [
            'slug' => 'manually-expired',
            'status' => FlyerStatus::Expired,
            // Manually expired but valid_until still in the future.
            'valid_until' => now('Africa/Cairo')->addDays(10)->toDateString(),
        ]);

        $response = $this->get("/{$retailerSlug}");

        $response->assertOk();
        $expiredFlyers = $response->viewData('expiredFlyers');
        $this->assertSame(1, $expiredFlyers->count());
        $this->assertSame('manually-expired', $expiredFlyers->first()->slug);

        // NOT in the active list.
        $activeFlyers = $response->viewData('activeFlyers');
        $this->assertSame(0, $activeFlyers->count());
    }

    public function test_naturally_lapsed_published_flyer_appears_in_archive(): void
    {
        $retailerSlug = 'kazyon-live';
        $this->createPublishedFlyer(['slug' => $retailerSlug], [
            'slug' => 'lapsed-offer',
            'status' => FlyerStatus::Published,
            'valid_until' => now('Africa/Cairo')->subDays(5)->toDateString(),
        ]);

        $response = $this->get("/{$retailerSlug}");

        $response->assertOk();
        $this->assertSame(1, $response->viewData('expiredFlyers')->count());
    }

    public function test_flyers_of_other_inactive_retailers_hidden_from_cross_links(): void
    {
        $retailerSlug = 'kazyon-live';
        $this->createPublishedFlyer(['slug' => $retailerSlug]);
        $this->createPublishedFlyer(['is_active' => false, 'slug' => 'other-dead'], [
            'slug' => 'other-dead-offer',
        ]);

        $response = $this->get("/{$retailerSlug}");

        $response->assertOk();
        $others = $response->viewData('otherActiveFlyers');
        $this->assertSame(0, $others->count());
    }

    public function test_markdown_response_headers(): void
    {
        $retailerSlug = 'kazyon-live';
        $this->createPublishedFlyer(['slug' => $retailerSlug]);

        $response = $this->get("/{$retailerSlug}?_fmt=md");

        $response->assertOk();
        $this->assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame(
            '<https://magalatna.com/'.$retailerSlug.'>; rel="canonical"',
            (string) $response->headers->get('Link'),
        );
    }

    public function test_html_response_has_vary_accept(): void
    {
        $this->createPublishedFlyer(['slug' => 'kazyon-live']);

        $response = $this->get('/kazyon-live');

        $response->assertOk();
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }
}
