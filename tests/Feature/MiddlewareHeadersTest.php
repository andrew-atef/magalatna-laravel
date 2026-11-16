<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\NegotiateMarkdownResponse;
use App\Http\Middleware\VerifyIngestWebhookSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class MiddlewareHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_vary_accept_is_merged_without_duplicate_headers(): void
    {
        $middleware = new NegotiateMarkdownResponse;

        $request = Request::create('/offers/some-offer');
        $response = new \Illuminate\Http\Response('html');
        $response->headers->set('Vary', 'Accept-Encoding, User-Agent');

        $result = $middleware->handle($request, static fn (): Response => $response);

        // Exactly ONE Vary header line, containing every original token + Accept.
        $varyHeaders = $result->headers->all('Vary');
        $this->assertCount(1, $varyHeaders);
        $this->assertSame('Accept-Encoding, User-Agent, Accept', $varyHeaders[0]);
    }

    public function test_vary_accept_added_once_when_absent(): void
    {
        $middleware = new NegotiateMarkdownResponse;

        $request = Request::create('/');
        $response = new \Illuminate\Http\Response('html');

        $result = $middleware->handle($request, static fn (): Response => $response);

        $varyHeaders = $result->headers->all('Vary');
        $this->assertCount(1, $varyHeaders);
        $this->assertSame('Accept', $varyHeaders[0]);
    }

    public function test_markdown_fallback_sets_noindex_when_controller_did_not(): void
    {
        $middleware = new NegotiateMarkdownResponse;

        $request = Request::create('/offers/some-offer', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = new \Illuminate\Http\Response('markdown');

        $result = $middleware->handle($request, static fn (): Response => $response);

        $this->assertSame('noindex', $result->headers->get('X-Robots-Tag'));
    }

    public function test_controller_x_robots_tag_is_never_downgraded(): void
    {
        $middleware = new NegotiateMarkdownResponse;

        $request = Request::create('/offers/some-offer', server: ['HTTP_ACCEPT' => 'text/markdown']);
        $response = new \Illuminate\Http\Response('markdown');
        $response->headers->set('X-Robots-Tag', 'noindex, follow');

        $result = $middleware->handle($request, static fn (): Response => $response);

        $this->assertSame('noindex, follow', $result->headers->get('X-Robots-Tag'));
    }

    public function test_header_secret_is_accepted(): void
    {
        config(['services.ingest_webhook.secret' => '  super-secret  ']);

        $middleware = new VerifyIngestWebhookSecret;
        $request = Request::create('/api/v1/radar', server: ['HTTP_X_INGEST_SECRET' => 'super-secret']);

        $result = $middleware->handle($request, static fn (): Response => new Response('ok', 200));

        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_env_fallback_is_gone_config_is_the_only_source(): void
    {
        // The secret exists in the ENVIRONMENT but NOT in config (simulating a
        // config:cache without the key). The old env() fallback would accept it.
        putenv('INGEST_WEBHOOK_SECRET=env-only-secret');

        try {
            // Clear BOTH secret sources so the 503 "not configured" branch is
            // reached; the core assertion is that the env value is NOT accepted.
            config([
                'services.ingest_webhook.secret' => null,
                'services.internal_api.key' => null,
            ]);

            $middleware = new VerifyIngestWebhookSecret;
            $request = Request::create('/api/v1/radar', server: ['HTTP_X_INGEST_SECRET' => 'env-only-secret']);

            $result = $middleware->handle($request, static fn (): Response => new Response('ok', 200));

            $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $result->getStatusCode());
        } finally {
            putenv('INGEST_WEBHOOK_SECRET');
        }
    }

    public function test_query_param_secret_still_authenticates_but_logs_deprecation(): void
    {
        config(['services.ingest_webhook.secret' => 'super-secret']);
        Log::spy();

        $middleware = new VerifyIngestWebhookSecret;
        $request = Request::create('/api/v1/radar?secret=super-secret');

        $result = $middleware->handle($request, static fn (): Response => new Response('ok', 200));

        $this->assertSame(200, $result->getStatusCode());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'query string'));
    }

    public function test_wrong_secret_is_rejected(): void
    {
        config(['services.ingest_webhook.secret' => 'super-secret']);

        $middleware = new VerifyIngestWebhookSecret;
        $request = Request::create('/api/v1/radar', server: ['HTTP_X_INGEST_SECRET' => 'wrong']);

        $result = $middleware->handle($request, static fn (): Response => new Response('ok', 200));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $result->getStatusCode());
    }
}
