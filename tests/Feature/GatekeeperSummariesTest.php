<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\PingIndexNowJob;
use App\Jobs\ProcessSinglePageJob;
use App\Models\RawFacebookPost;
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

final class GatekeeperSummariesTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);

        // Neutralise outbound Gemini calls: the deterministic fallback builders
        // are what these tests exercise.
        Http::fake([
            '*' => Http::response([], 500),
        ]);
    }

    public function test_bluf_never_advertises_zero_percent_discount(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'zero-discount-offer']);
        $this->addItem($flyer, ['sale_price' => 90.0, 'old_price' => null, 'discount_percent' => null]);

        $summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);

        $this->assertStringNotContainsString('0%', $summary);
        $this->assertStringNotContainsString('0.00%', $summary);
        $this->assertStringContainsString('بأسعار تنافسية مخفضة', $summary);
    }

    public function test_bluf_includes_real_discount_when_present(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'real-discount-offer']);
        $this->addItem($flyer, ['sale_price' => 90.0, 'old_price' => 120.0, 'discount_percent' => 25.0]);

        $summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);

        $this->assertStringContainsString('بخصومات تصل إلى 25%', $summary);
    }

    public function test_bluf_survives_empty_valid_until_without_exception(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'no-date-offer']);
        $this->addItem($flyer);

        // The column is NOT NULL, but corrupted legacy rows / raw attribute
        // injection can still surface an empty string. Simulate it by writing
        // the raw attribute directly (bypasses casts and DB constraints).
        $flyer->setRawAttributes(array_merge($flyer->getAttributes(), ['valid_until' => '']), true);

        $summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);

        // Never Carbon::parse('') — must produce usable Arabic copy regardless.
        $this->assertStringContainsString('تصفح', $summary);
        $this->assertNotSame('', trim($summary));
    }

    public function test_editorial_overview_trims_decimals_before_currency_suffix(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'price-format-offer']);
        $this->addItem($flyer, ['sale_price' => 90.50, 'old_price' => 120.00, 'discount_percent' => 24.58]);

        $overview = GatekeeperFacebookPostJob::buildEditorialOverview($flyer);

        // ".00" must be trimmed BEFORE appending " ج.م" — the old rtrim-on-
        // finished-string approach hit the Arabic suffix and was a no-op.
        $this->assertStringContainsString('90.5 ج.م', $overview);
        $this->assertStringContainsString('120 ج.م', $overview);
        $this->assertStringNotContainsString('90.50 ج.م', $overview);
        $this->assertStringNotContainsString('120.00 ج.م', $overview);
    }

    public function test_zero_items_batch_then_deletes_flyer_queues_r2_reaping_and_never_pings_indexnow(): void
    {
        config(['services.cloudflare.api_token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/pages/kill-switch-page.webp', 'page-bytes');
        Queue::fake();
        // Fake ONLY the page-processing job: batches are still intercepted (so
        // we can run then() manually), while afterCommit closures flow through
        // to the QueueFake exactly like production workers would see them.
        Bus::fake([ProcessSinglePageJob::class]);

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'kill-switch-offer', 'status' => FlyerStatus::Draft]);
        $this->addPage($flyer, 'flyers/pages/kill-switch-page.webp');

        $rawPost = RawFacebookPost::query()->create([
            'retailer_id' => $flyer->retailer_id,
            'facebook_post_id' => 'kill-switch-post',
            'post_text' => 'نص المنشور',
            'image_urls' => [],
            'status' => 'pending',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        $job = new GatekeeperFacebookPostJob(
            retailerSlug: $flyer->retailer->slug,
            facebookPostId: 'kill-switch-post',
            postText: 'نص المنشور',
            imageUrls: [],
            publishedAt: now('UTC')->toIso8601String(),
            rawFacebookPostId: $rawPost->id,
        );

        $method = new \ReflectionMethod($job, 'dispatchPageProcessingBatch');
        $method->invoke($job, flyerId: $flyer->id, imageUrls: [], startPageNumber: 0, batchName: 'test-kill-switch');

        // Empty job list => the batch completes immediately on the worker. The
        // sync driver does not fire then() for empty batches, so execute the
        // captured then() callback exactly as the batch worker would.
        Bus::assertBatched(function (PendingBatch $pendingBatch): bool {
            $batch = new Batch(
                app(Factory::class),
                app(BatchRepository::class),
                'test-batch-id',
                $pendingBatch->name ?? 'test-kill-switch',
                0,
                0,
                0,
                [],
                $pendingBatch->options,
                CarbonImmutable::now(),
            );

            foreach ($pendingBatch->options['then'] ?? [] as $callback) {
                $callback($batch);
            }

            return true;
        });

        // Flyer (and cascade pages) deleted, raw post rejected + unlinked.
        $this->assertDatabaseMissing('flyers', ['id' => $flyer->id]);
        $this->assertDatabaseMissing('flyer_pages', ['flyer_id' => $flyer->id]);
        $this->assertSame('rejected', $rawPost->refresh()->status);
        $this->assertNull($rawPost->flyer_id);

        // R2 reaping ran through the observer's afterCommit closure.
        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $closure) => $closure->handle(app()));
        Storage::disk('r2')->assertMissing('flyers/pages/kill-switch-page.webp');

        // A killed flyer is dead content — IndexNow must never be pinged for it.
        Queue::assertNotPushed(PingIndexNowJob::class);
    }
}
