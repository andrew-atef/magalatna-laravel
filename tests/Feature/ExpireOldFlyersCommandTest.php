<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\PingIndexNowJob;
use App\Jobs\PurgeCloudflareCacheJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class ExpireOldFlyersCommandTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_expires_lapsed_flyers_with_canonical_purge_urls_and_no_indexnow(): void
    {
        // Fixtures FIRST: the Published creation fires real observer purges.
        // Faking afterwards isolates exactly what the COMMAND dispatches.
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'lapsed-offer',
            'valid_until' => now('Africa/Cairo')->subDay()->toDateString(),
        ]);

        Queue::fake();

        Cache::put('sitemap_xml_content', '<old/>', 600);
        Cache::put('llms_txt_content', 'old llms', 600);

        $this->artisan('flyers:expire')->assertSuccessful();

        $this->assertSame(FlyerStatus::Expired, $flyer->refresh()->status);

        // Both SEO artifacts invalidated.
        $this->assertNull(Cache::get('sitemap_xml_content'));
        $this->assertNull(Cache::get('llms_txt_content'));

        // Canonical config-based URLs (incl. llms.txt), never url('/').
        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            return in_array('https://magalatna.com/offers/lapsed-offer', $job->urls, true)
                && in_array('https://magalatna.com/kazyon-live', $job->urls, true)
                && in_array('https://magalatna.com/sitemap.xml', $job->urls, true)
                && in_array('https://magalatna.com/llms.txt', $job->urls, true)
                && in_array('https://magalatna.com/', $job->urls, true);
        });

        // Expired URLs are NOT indexable — IndexNow must never be pinged here.
        Queue::assertNotPushed(PingIndexNowJob::class);
    }

    public function test_flyer_expiring_today_stays_published(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'still-live-offer',
            'valid_until' => now('Africa/Cairo')->toDateString(),
        ]);

        Queue::fake();

        $this->artisan('flyers:expire')->assertSuccessful();

        $this->assertSame(FlyerStatus::Published, $flyer->refresh()->status);
        Queue::assertNotPushed(PurgeCloudflareCacheJob::class);
    }
}
