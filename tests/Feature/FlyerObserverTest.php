<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\PingIndexNowJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Observers\FlyerObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class FlyerObserverTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_unpublishing_published_flyer_dispatches_purge_with_canonical_urls(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'kazyon-offer']);

        Queue::fake();

        // Published -> Draft transition (unpublish).
        $flyer->status = FlyerStatus::Draft;
        $flyer->save();

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            $urls = $job->urls;

            $this->assertContains('https://magalatna.com/offers/kazyon-offer', $urls);
            $this->assertContains('https://magalatna.com/', $urls);
            $this->assertContains('https://magalatna.com/sitemap.xml', $urls);
            $this->assertContains('https://magalatna.com/llms.txt', $urls);

            // Canonical host from config('app.url') — never request-bound.
            foreach ($urls as $url) {
                $this->assertStringStartsWith('https://magalatna.com', $url);
            }

            return true;
        });
    }

    public function test_publishing_dispatches_purge_and_indexnow_ping(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'fresh-offer', 'status' => FlyerStatus::Draft]);

        Queue::fake();

        $flyer->status = FlyerStatus::Published;
        $flyer->save();

        Queue::assertPushed(PurgeCloudflareCacheJob::class);
        Queue::assertPushed(PingIndexNowJob::class, function (PingIndexNowJob $job): bool {
            // Batched urlList: indexable pages only (offer + retailer hub + home).
            $this->assertIsArray($job->pageUrl);
            $this->assertContains('https://magalatna.com/offers/fresh-offer', $job->pageUrl);
            $this->assertContains('https://magalatna.com/kazyon-live', $job->pageUrl);
            $this->assertContains('https://magalatna.com/', $job->pageUrl);

            // IndexNow separation: sitemap/llms are NEVER pinged.
            $this->assertNotContains('https://magalatna.com/sitemap.xml', $job->pageUrl);
            $this->assertNotContains('https://magalatna.com/llms.txt', $job->pageUrl);

            return true;
        });
    }

    public function test_updating_bluf_summary_dispatches_purge(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'bluf-offer']);

        Queue::fake();

        $flyer->bluf_summary = 'جملة أولى بخصومات 20%. جملة ثانية توضح السلع المخفضة في المجلة.';
        $flyer->save();

        Queue::assertPushed(PurgeCloudflareCacheJob::class);
    }

    public function test_purge_includes_sibling_flyer_urls_of_same_retailer(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'main-offer']);

        // Sibling flyer belonging to the SAME retailer (reuse retailer_id).
        Flyer::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'title' => 'مجلة شقيقة',
            'slug' => 'sibling-offer',
            'valid_from' => now('Africa/Cairo')->subDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);

        Queue::fake();

        $flyer->title = 'عنوان محدث للمجلة';
        $flyer->save();

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            $this->assertContains('https://magalatna.com/offers/sibling-offer', $job->urls);

            return true;
        });
    }

    public function test_deleting_flyer_captures_r2_paths_before_cascade_and_queues_deletion(): void
    {
        Storage::fake('r2');

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'doomed-offer']);
        $this->addPage($flyer, 'flyers/pages/doomed-page-1.webp');
        $this->addPage($flyer, 'flyers/pages/doomed-page-2.webp', 2);

        Storage::disk('r2')->put('flyers/pages/doomed-page-1.webp', 'img1');
        Storage::disk('r2')->put('flyers/pages/doomed-page-2.webp', 'img2');

        Queue::fake();

        $flyerId = $flyer->id;
        $flyer->delete();

        // R2 paths were captured BEFORE the ON DELETE CASCADE wiped flyer_pages.
        Queue::assertPushed(PurgeCloudflareCacheJob::class);

        $closureJobs = Queue::pushed(CallQueuedClosure::class);
        $this->assertGreaterThanOrEqual(1, $closureJobs->count());

        // Execute the queued R2 deletion closures exactly like a worker would.
        $closureJobs->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        Storage::disk('r2')->assertMissing('flyers/pages/doomed-page-1.webp');
        Storage::disk('r2')->assertMissing('flyers/pages/doomed-page-2.webp');

        // Cascade really wiped the pages rows.
        $this->assertSame(0, DB::table('flyer_pages')->where('flyer_id', $flyerId)->count());
    }

    public function test_static_state_map_is_cleared_after_save(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'map-offer']);

        $reflection = new \ReflectionClass(FlyerObserver::class);
        $map = $reflection->getProperty('stateBeforeUpdate');

        // Simulate an updating event then a full save cycle.
        $observer = new FlyerObserver;
        $observer->updating($flyer);
        $this->assertNotEmpty($map->getValue());

        $flyer->saveQuietly(); // no events — call the observer hook directly
        $observer->saved($flyer);

        // Memory-leak guard: transient map must be empty after saved().
        $this->assertSame([], $map->getValue());
    }
}
