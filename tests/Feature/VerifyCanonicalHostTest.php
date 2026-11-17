<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCanonicalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class VerifyCanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the canonical host the middleware must enforce (the production
        // value). The test env inherits APP_URL=http://localhost, under which
        // "www.localhost" requests would correctly NOT be redirected.
        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_www_host_301s_to_apex_preserving_path_and_query(): void
    {
        // Short-circuits in middleware BEFORE any controller/db work.
        $response = $this->get('https://www.magalatna.com/about-us?param=1&other=2');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/about-us?param=1&other=2');
    }

    public function test_apex_host_is_never_redirected(): void
    {
        $response = $this->get('https://magalatna.com/about-us');

        $response->assertStatus(200);
    }

    public function test_localhost_and_dev_hosts_are_never_redirected(): void
    {
        $middleware = new VerifyCanonicalHost;

        $request = Request::create('http://localhost/about-us');
        $result = $middleware->handle($request, static fn (): Response => new Response('ok'));

        $this->assertSame('ok', $result->getContent());
    }

    public function test_www_redirect_preserves_deep_path_and_query(): void
    {
        $middleware = new VerifyCanonicalHost;

        $request = Request::create('https://www.magalatna.com/offers/some-flyer?page=2&q=rice');
        $result = $middleware->handle($request, static fn (): Response => new Response('ok'));

        $this->assertSame(301, $result->getStatusCode());
        $this->assertSame(
            'https://magalatna.com/offers/some-flyer?page=2&q=rice',
            $result->headers->get('Location'),
        );
    }
}
