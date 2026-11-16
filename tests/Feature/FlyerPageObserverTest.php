<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\PingIndexNowJob;
use App\Jobs\PurgeCloudflareCacheJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class FlyerPageObserverTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_replacing_image_path_queues_old_r2_delete_and_purges_published_parent(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/pages/old-key.webp', 'old-bytes');

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'page-swap-offer']);
        $page = $this->addPage($flyer, 'flyers/pages/old-key.webp');

        Queue::fake();

        $page->image_path = 'flyers/pages/new-key.webp';
        $page->save();

        // The old R2 object is reaped by a queued closure (afterCommit).
        $closureJobs = Queue::pushed(CallQueuedClosure::class);
        $this->assertGreaterThanOrEqual(1, $closureJobs->count());
        $closureJobs->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        Storage::disk('r2')->assertMissing('flyers/pages/old-key.webp');

        // The rendered flyer page changed → parent canonical URL purged.
        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            return in_array('https://magalatna.com/offers/page-swap-offer', $job->urls, true);
        });

        // Image swaps are NOT indexable events — never ping IndexNow.
        Queue::assertNotPushed(PingIndexNowJob::class);
    }

    public function test_same_image_path_update_is_a_noop(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'noop-offer']);
        $page = $this->addPage($flyer, 'flyers/pages/same-key.webp');

        Queue::fake();

        $page->image_path = 'flyers/pages/same-key.webp';
        $page->save();

        Queue::assertNotPushed(PurgeCloudflareCacheJob::class);
        Queue::assertNotPushed(CallQueuedClosure::class);
    }

    public function test_replaced_image_on_draft_parent_does_not_purge(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'draft-page-offer', 'status' => FlyerStatus::Draft]);
        $page = $this->addPage($flyer, 'flyers/pages/draft-old.webp');

        Queue::fake();

        $page->image_path = 'flyers/pages/draft-new.webp';
        $page->save();

        // Old R2 object is still reaped (leak protection is unconditional)...
        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        // ...but a Draft flyer is not public — no CDN purge, no ping.
        Queue::assertNotPushed(PurgeCloudflareCacheJob::class);
        Queue::assertNotPushed(PingIndexNowJob::class);
    }
}
