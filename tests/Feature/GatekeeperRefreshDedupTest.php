<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\PingIndexNowJob;
use App\Jobs\ProcessSinglePageJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\RawFacebookPost;
use App\Services\FlyerSlugService;
use App\Services\GeminiVisionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Verifies the merged production guards inside GatekeeperFacebookPostJob:
 * the [REFRESH] in-place rebuild (zero R2 leaks), [GATEKEEPER_DEDUP] item
 * deduplication, the upgrade-batch stand-down branch, and the full
 * FlyerObserver purge firing on the audited save().
 */
final class GatekeeperRefreshDedupTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    /**
     * Run the captured batch's then() callback exactly as a batch worker would.
     */
    private function runCapturedThen(PendingBatch $pendingBatch): void
    {
        $batch = new Batch(
            app(Factory::class),
            app(BatchRepository::class),
            'test-batch-id',
            $pendingBatch->name ?? 'test-batch',
            count($pendingBatch->jobs),
            0,
            0,
            [],
            $pendingBatch->options,
            CarbonImmutable::now(),
        );

        foreach ($pendingBatch->options['then'] ?? [] as $callback) {
            $callback($batch);
        }
    }

    public function test_refresh_guard_rebuilds_single_post_flyer_in_place_without_r2_leaks(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/pages/stale-1.webp', 'stale-1');
        Storage::disk('r2')->put('flyers/pages/stale-2.webp', 'stale-2');
        Queue::fake();
        Bus::fake([ProcessSinglePageJob::class]);

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.5-flash',
        ]);

        $flyer = $this->createPublishedFlyer(
            ['slug' => 'kazyon-live'],
            ['slug' => 'kazyon-weekly-offer', 'total_pages' => 2],
        );
        $this->addPage($flyer, 'flyers/pages/stale-1.webp', 1);
        $this->addPage($flyer, 'flyers/pages/stale-2.webp', 2);

        $rawPost = RawFacebookPost::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'facebook_post_id' => 'refresh-post-1',
            'post_text' => 'نص المنشور الأصلي',
            'image_urls' => ['https://scontent.fbcdn.net/img/old-1.jpg', 'https://scontent.fbcdn.net/img/old-2.jpg'],
            'status' => 'accepted',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        // Classification dates MUST equal the flyer's exact range so the
        // exact-range match inside the lock resolves to the same flyer.
        // GeminiVisionService is final — drive the real service through an
        // HTTP fake: the Gemini endpoint returns the classification envelope,
        // while sample-image downloads fail gracefully to text-only mode.
        $classification = [
            'is_flyer' => true,
            'reason' => 'explicit promotion',
            'flyer_title' => 'مجلة كازيون الأسبوعية',
            'valid_from' => (string) $flyer->valid_from,
            'valid_until' => (string) $flyer->valid_until,
            'applicable_governorates' => [],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($classification, JSON_UNESCAPED_UNICODE)]]]],
                ],
            ], 200),
            '*' => Http::response('', 404),
        ]);

        $job = new GatekeeperFacebookPostJob(
            retailerSlug: $flyer->retailer->slug,
            facebookPostId: 'refresh-post-1',
            postText: 'نص المنشور الأصلي',
            imageUrls: [
                'https://scontent.fbcdn.net/img/new-1.jpg',
                'https://scontent.fbcdn.net/img/new-2.jpg',
                'https://scontent.fbcdn.net/img/new-3.jpg',
            ],
            publishedAt: now('UTC')->toIso8601String(),
            rawFacebookPostId: $rawPost->id,
        );

        $job->handle(app(GeminiVisionService::class), app(FlyerSlugService::class));
        // Rebuild IN PLACE: same flyer row and slug, teardown done, counter
        // reset to the refreshed set size.
        $flyer->refresh();
        $this->assertSame('kazyon-weekly-offer', $flyer->slug);
        $this->assertSame(3, (int) $flyer->total_pages);
        $this->assertDatabaseMissing('flyer_pages', ['flyer_id' => $flyer->id]);
        $this->assertSame(0, $flyer->pages()->count());

        // Fresh full-set processing batch: pages 1..3 of the refreshed payload.
        Bus::assertBatched(function (PendingBatch $pendingBatch): bool {
            $this->assertStringContainsString('-refresh', (string) $pendingBatch->name);

            $jobs = $pendingBatch->jobs->all();
            $this->assertCount(3, $jobs);
            foreach ($jobs as $index => $job) {
                $this->assertInstanceOf(ProcessSinglePageJob::class, $job);
                $this->assertSame($index + 1, $job->pageNumber);
            }

            return true;
        });

        // ZERO R2 LEAK: both stale page objects are reaped afterCommit.
        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $closure) => $closure->handle(app()));
        Storage::disk('r2')->assertMissing('flyers/pages/stale-1.webp');
        Storage::disk('r2')->assertMissing('flyers/pages/stale-2.webp');
    }

    public function test_finalize_flyer_batch_dedups_items_and_fires_full_observer_purge(): void
    {
        config([
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'test-zone',
        ]);

        // Fixtures BEFORE Queue::fake(): the observer purge fired by creating a
        // Published flyer must not be captured — the purge asserted below can
        // then only originate from finalizeFlyerBatch's audited save().
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'kazyon-live'],
            ['slug' => 'kazyon-dedup-offer', 'total_pages' => 1],
        );
        $this->addPage($flyer, 'flyers/pages/dedup-page.webp', 1);

        Queue::fake();
        Bus::fake([ProcessSinglePageJob::class]);

        $job = new GatekeeperFacebookPostJob(
            retailerSlug: $flyer->retailer->slug,
            facebookPostId: 'dedup-post-1',
            postText: 'نص المنشور',
            imageUrls: ['https://scontent.fbcdn.net/img/p1.jpg'],
            publishedAt: now('UTC')->toIso8601String(),
            rawFacebookPostId: null,
        );

        $method = new \ReflectionMethod($job, 'dispatchPageProcessingBatch');
        $method->invoke($job, flyerId: $flyer->id, imageUrls: ['https://scontent.fbcdn.net/img/p1.jpg'], startPageNumber: 0, batchName: 'test-dedup-batch');

        // Simulate OCR results: one exact duplicate pair (name+price), plus one
        // distinct product. [GATEKEEPER_DEDUP] must remove exactly one.
        $this->addItem($flyer, ['product_name' => 'زيت عافية 1.5 لتر', 'sale_price' => 90.0]);
        $this->addItem($flyer, ['product_name' => "زيت  عافية\t1.5 لتر", 'sale_price' => 90.0]);
        $this->addItem($flyer, ['product_name' => 'أرز أبو كاس 1 كجم', 'slug' => 'rice-1kg', 'sale_price' => 40.0]);

        Bus::assertBatched(function (PendingBatch $pendingBatch): bool {
            $this->runCapturedThen($pendingBatch);

            return true;
        });

        $flyer->refresh();
        $this->assertSame(2, $flyer->items()->count(), 'Duplicate [name, price] item must be removed.');
        $this->assertSame(1, (int) $flyer->total_pages, 'total_pages recounted from the DB.');
        $this->assertSame(FlyerStatus::Published, $flyer->status);
        $this->assertNotNull($flyer->bluf_summary);
        $this->assertNotNull($flyer->editorial_overview);

        // The audited save() (bluf/editorial changed) fired the SINGLE
        // authoritative Cloudflare purge with the full canonical URL set.
        $offerPath = route('flyers.show', ['slug' => 'kazyon-dedup-offer'], false);
        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job) use ($offerPath): bool {
            return in_array('https://magalatna.com'.$offerPath, $job->urls, true)
                && in_array('https://magalatna.com/sitemap.xml', $job->urls, true);
        });

        // IndexNow pinged for the live Published offer.
        Queue::assertPushed(PingIndexNowJob::class, function (PingIndexNowJob $job) use ($offerPath): bool {
            return in_array('https://magalatna.com'.$offerPath, (array) $job->pageUrl, true);
        });
    }

    public function test_zero_price_upgrade_batch_stands_down_and_keeps_flyer_alive(): void
    {
        config([
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'test-zone',
        ]);
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/pages/stand-down-page.webp', 'page-bytes');

        // Fixtures BEFORE Queue::fake(): only events fired while the fake is
        // active are assertable (the fixture's own publish purge must not pollute).
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'kazyon-live'],
            ['slug' => 'kazyon-stand-down-offer', 'total_pages' => 5],
        );
        $this->addPage($flyer, 'flyers/pages/stand-down-page.webp', 1);

        $rawPost = RawFacebookPost::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'facebook_post_id' => 'stand-down-post',
            'post_text' => 'نص المنشور',
            'image_urls' => [],
            'status' => 'accepted',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        Queue::fake();

        $batch = new Batch(
            app(Factory::class),
            app(BatchRepository::class),
            'stand-down-batch-id',
            'test-stand-down',
            0,
            0,
            0,
            [],
            [],
            CarbonImmutable::now(),
        );

        // Upgrade/rebuild batch (rawPostId: null, deleteOnZeroItems: false):
        // a partial re-extraction yielding 0 priced items must NEVER kill a
        // live flyer — recount, stand down, no purge, no rejection.
        GatekeeperFacebookPostJob::finalizeFlyerBatch($batch, (int) $flyer->id, rawPostId: null, deleteOnZeroItems: false);

        $flyer->refresh();
        $this->assertDatabaseHas('flyers', ['id' => $flyer->id]);
        $this->assertSame(1, (int) $flyer->total_pages, 'total_pages recounted from surviving pages.');
        $this->assertSame('accepted', $rawPost->refresh()->status, 'Raw post must NOT be rejected by an upgrade batch.');
        $this->assertSame(FlyerStatus::Published, $flyer->status);

        // Stand-down used saveQuietly: no observer purge/ping fired.
        Queue::assertNotPushed(PurgeCloudflareCacheJob::class);
        Queue::assertNotPushed(PingIndexNowJob::class);

        // The page object stays in R2 — the flyer is still live.
        Storage::disk('r2')->assertExists('flyers/pages/stand-down-page.webp');
    }

    public function test_finalize_flyer_batch_handles_missing_flyer_without_throwing(): void
    {
        Queue::fake();

        $batch = new Batch(
            app(Factory::class),
            app(BatchRepository::class),
            'ghost-batch-id',
            'test-ghost',
            0,
            0,
            0,
            [],
            [],
            CarbonImmutable::now(),
        );

        // Deleted while the batch ran: must log-and-return, never crash the
        // batch worker (and never crash on undefined $itemsCount either).
        GatekeeperFacebookPostJob::finalizeFlyerBatch($batch, 999999);

        $this->assertDatabaseMissing('flyers', ['id' => 999999]);
        Queue::assertNotPushed(PurgeCloudflareCacheJob::class);
    }
}
