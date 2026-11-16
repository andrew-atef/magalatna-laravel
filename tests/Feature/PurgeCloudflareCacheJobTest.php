<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PingIndexNowJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Services\CloudflareCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PurgeCloudflareCacheJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'test-zone',
            'services.indexnow.key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);
    }

    public function test_purge_never_fans_out_indexnow_pings(): void
    {
        Queue::fake();

        // Mix of indexable and non-indexable URLs: the purge job purges the
        // edge for ALL of them but must NEVER dispatch IndexNow for any.
        $job = new PurgeCloudflareCacheJob([
            'https://magalatna.com/offers/some-offer',
            'https://magalatna.com/sitemap.xml',
            'https://magalatna.com/llms.txt',
            'https://magalatna.com/deleted-page-that-used-to-exist',
        ]);

        $job->handle(new CloudflareCacheService);

        Queue::assertNotPushed(PingIndexNowJob::class);

        // The purge itself still reached the Cloudflare edge API.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'purge_cache'));
    }

    public function test_purge_handles_empty_url_list_without_dispatches(): void
    {
        Queue::fake();

        $job = new PurgeCloudflareCacheJob(['', '   ']);

        $job->handle(new CloudflareCacheService);

        Queue::assertNotPushed(PingIndexNowJob::class);
        Http::assertNothingSent();
    }
}
