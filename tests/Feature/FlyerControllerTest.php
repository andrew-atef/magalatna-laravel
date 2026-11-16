<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class FlyerControllerTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_draft_flyer_returns_404(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'draft-me', 'status' => FlyerStatus::Draft]);

        $this->get('/offers/draft-me')
            ->assertNotFound();
    }

    public function test_pending_review_flyer_returns_404(): void
    {
        $this->createPublishedFlyer([], ['slug' => 'pending-me', 'status' => FlyerStatus::PendingReview]);

        $this->get('/offers/pending-me')
            ->assertNotFound();
    }

    public function test_flyer_of_inactive_retailer_returns_404(): void
    {
        $flyer = $this->createPublishedFlyer(['is_active' => false, 'slug' => 'dead-retailer'], ['slug' => 'hidden-offer']);

        $this->assertSame(
            FlyerStatus::Published->value,
            (string) DB::table('flyers')->where('id', $flyer->id)->value('status'),
        );

        $this->get('/offers/hidden-offer')
            ->assertNotFound();
    }

    public function test_expired_flyer_still_renders(): void
    {
        $this->createPublishedFlyer([], [
            'slug' => 'expired-but-public',
            'status' => FlyerStatus::Expired,
        ]);

        $this->get('/offers/expired-but-public')
            ->assertOk();
    }

    public function test_published_flyer_returns_200_with_vary_accept(): void
    {
        $this->createPublishedFlyer([], ['slug' => 'live-offer']);

        $response = $this->get('/offers/live-offer');

        $response->assertOk();
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_markdown_response_has_noindex_and_canonical_headers(): void
    {
        $this->createPublishedFlyer([], ['slug' => 'md-offer']);

        $response = $this->get('/offers/md-offer?_fmt=md');

        $response->assertOk();
        $this->assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame(
            '<https://magalatna.com/offers/md-offer>; rel="canonical"',
            (string) $response->headers->get('Link'),
        );
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_offers_route_is_named(): void
    {
        $this->assertTrue(Route::has('flyers.show'));
        $this->assertTrue(Route::has('retailers.show'));
    }
}
