<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\StatelessRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Stateless Public Pages — anonymous public GETs must not emit session
 * cookies or a dynamic csrf-token meta, while /admin and /livewire keep the
 * FULL `web` session middleware.
 */
final class StatelessPublicPagesTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_home_get_is_cookie_free_and_csrf_meta_free(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertNull($response->headers->get('Set-Cookie'));

        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('csrf-token', $html);
        // Canonical home URL unified WITH trailing slash.
        $this->assertStringContainsString('<link rel="canonical" href="https://magalatna.com/">', $html);
    }

    public function test_flyer_get_is_cookie_free(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'stateless-offer']);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $response = $this->get('/offers/stateless-offer')->assertOk();

        $this->assertNull($response->headers->get('Set-Cookie'));
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('csrf-token', $html);
        // CTR title: retailer + dates, and word-safe <= 60 chars core.
        $this->assertStringContainsString('عروض كازيون', $html);
    }

    public function test_legacy_flyer_redirects_are_cookie_free(): void
    {
        $response = $this->get('/flyer/anything-slug');

        $this->assertSame(301, $response->status());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_pagination_self_canonical_on_home_page_2(): void
    {
        $this->get('/?page=2')->assertOk()->assertSee(
            '<link rel="canonical" href="https://magalatna.com/?page=2">',
            false,
        );
    }

    public function test_admin_keeps_session_cookies(): void
    {
        // Filament panel (login redirect) must STILL emit its session cookie —
        // only the stateless PUBLIC routes are stripped.
        $response = $this->get('/admin');

        $this->assertNotNull($response->headers->get('Set-Cookie'));
    }

    public function test_stateless_route_helper_matches_exactly(): void
    {
        $create = fn (string $uri, string $method = 'GET') => \Illuminate\Http\Request::create($uri, $method);

        $this->assertTrue(StatelessRoutes::isStatelessRoute($create('/')));
        $this->assertTrue(StatelessRoutes::isStatelessRoute($create('/about-us')));
        $this->assertTrue(StatelessRoutes::isStatelessRoute($create('/offers/some-slug')));
        $this->assertTrue(StatelessRoutes::isStatelessRoute($create('/flyer/legacy-slug')));
        $this->assertTrue(StatelessRoutes::isStatelessRoute($create('/sitemap.xml')));

        // Non-GET never stateless.
        $this->assertFalse(StatelessRoutes::isStatelessRoute($create('/contact-us', 'POST')));

        // Admin / Livewire keep full session middleware.
        $this->assertFalse(StatelessRoutes::isStatelessRoute($create('/admin')));
        $this->assertFalse(StatelessRoutes::isStatelessRoute($create('/livewire/update', 'POST')));
    }
}
