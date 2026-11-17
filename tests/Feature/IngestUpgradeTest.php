<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\ProcessSinglePageJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Verifies the merged production UPGRADE paths in RawFacebookIngestController:
 * a richer image set on a known post must rebuild/append instead of spawning
 * -part-2 flyers, stale R2 pages must be reaped asynchronously, and the
 * hardened guards (SSRF, kill-switch) must survive the merge untouched.
 */
final class IngestUpgradeTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    private Retailer $retailer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.ingest_webhook.secret' => 'test-secret',
        ]);

        // Swallow any outbound purge HTTP (controller rebuilds never purge, but
        // this keeps the suite hermetic if the observer ever fires).
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        /** @var Retailer $retailer */
        $retailer = Retailer::query()->create([
            'name' => 'كارفور مصر',
            'slug' => 'carrefour-'.uniqid(),
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ]);

        $this->retailer = $retailer;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'retailer_slug' => $this->retailer->slug,
            'facebook_post_id' => 'post-'.uniqid(),
            'post_text' => 'خصومات نهاية الأسبوع على جميع السلع الغذائية',
            'image_urls' => ['https://scontent.fbcdn.net/img/photo-1.jpg'],
            'published_at' => '2026-11-15T10:00:00Z',
        ], $overrides);
    }

    private function postIngest(array $payload)
    {
        return $this->postJson('/api/v1/facebook/ingest', $payload, ['X-Ingest-Secret' => 'test-secret']);
    }

    public function test_richer_image_set_rebuilds_single_post_flyer_in_place_and_reaps_stale_r2(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/pages/old-1.webp', 'old-bytes');
        Queue::fake();
        // Fake ONLY the page-processing job: batches are still intercepted so
        // the rebuild batch's shape is assertable without running OCR.
        Bus::fake([ProcessSinglePageJob::class]);

        // The flyer MUST belong to the retailer the POST targets — the exact
        // (retailer_id, facebook_post_id) match is what feeds the upgrade gate.
        $flyer = Flyer::query()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'مجلة كارفور الأسبوعية',
            'slug' => 'carrefour-weekly-offer',
            'valid_from' => now('Africa/Cairo')->subDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);
        $this->addPage($flyer, 'flyers/pages/old-1.webp', 1);
        $this->addItem($flyer);

        $rawPost = RawFacebookPost::query()->create([
            'retailer_id' => $this->retailer->id,
            'facebook_post_id' => 'upgrade-post-1',
            'post_text' => 'خصومات نهاية الأسبوع على جميع السلع الغذائية',
            'image_urls' => ['https://scontent.fbcdn.net/img/photo-1.jpg'],
            'status' => 'accepted',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        // Same photo-1 served from a rotated host with a query string must
        // collapse by signature; photo-2/3 are the genuinely richer set.
        $response = $this->postIngest($this->payload([
            'facebook_post_id' => 'upgrade-post-1',
            'image_urls' => [
                'https://scontent-cdg2-1.xx.fbcdn.net/img/photo-1.jpg?se=7',
                'https://scontent.fbcdn.net/img/photo-2.jpg',
                'https://scontent.fbcdn.net/img/photo-3.jpg',
            ],
        ]));

        $response->assertStatus(202);
        $this->assertTrue((bool) $response->json('queued'));
        $this->assertTrue((bool) $response->json('upgraded'));

        // Rebuild IN PLACE: same flyer row, same slug, no -part-2.
        $flyer->refresh();
        $this->assertSame('carrefour-weekly-offer', $flyer->slug);
        $this->assertSame(3, (int) $flyer->total_pages);
        $this->assertDatabaseMissing('flyer_pages', ['flyer_id' => $flyer->id]);

        // Stored payload grew to the merged superset — never shrunk.
        $rawPost->refresh();
        $this->assertCount(3, $rawPost->image_urls);

        // The rebuild batch carries ALL merged pages, numbered 1..3.
        Bus::assertBatched(function (PendingBatch $pendingBatch): bool {
            $this->assertStringContainsString('-rebuilt', (string) $pendingBatch->name);

            $jobs = $pendingBatch->jobs->all();
            $this->assertCount(3, $jobs);
            foreach ($jobs as $index => $job) {
                $this->assertInstanceOf(ProcessSinglePageJob::class, $job);
                $this->assertSame($index + 1, $job->pageNumber);
            }

            return true;
        });

        // NO re-dispatch of the whole Gatekeeper pipeline for an upgrade.
        Queue::assertNotPushed(GatekeeperFacebookPostJob::class);

        // The stale page's R2 object is reaped via the afterCommit closure.
        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $closure) => $closure->handle(app()));
        Storage::disk('r2')->assertMissing('flyers/pages/old-1.webp');
    }

    public function test_richer_image_set_on_multi_post_flyer_appends_only_new_pages(): void
    {
        Queue::fake();
        Bus::fake([ProcessSinglePageJob::class]);

        $flyer = Flyer::query()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'مجلة كارفور المشتركة',
            'slug' => 'carrefour-shared-offer',
            'valid_from' => now('Africa/Cairo')->subDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);
        $this->addPage($flyer, 'flyers/pages/shared-1.webp', 1);

        RawFacebookPost::query()->create([
            'retailer_id' => $this->retailer->id,
            'facebook_post_id' => 'shared-post-a',
            'post_text' => 'الجزء الأول',
            'image_urls' => ['https://scontent.fbcdn.net/img/shared-1.jpg'],
            'status' => 'accepted',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        $rawPostB = RawFacebookPost::query()->create([
            'retailer_id' => $this->retailer->id,
            'facebook_post_id' => 'shared-post-b',
            'post_text' => 'الجزء الثاني',
            'image_urls' => ['https://scontent.fbcdn.net/img/shared-1.jpg'],
            'status' => 'accepted',
            'flyer_id' => $flyer->id,
            'published_at' => now('UTC'),
        ]);

        // Two raw posts own this flyer → APPEND path (never tear down shared pages).
        $response = $this->postIngest($this->payload([
            'facebook_post_id' => 'shared-post-b',
            'post_text' => 'الجزء الثاني',
            'image_urls' => [
                'https://scontent.fbcdn.net/img/shared-1.jpg',
                'https://scontent.fbcdn.net/img/shared-2.jpg',
            ],
        ]));

        $response->assertStatus(202);
        $this->assertTrue((bool) $response->json('upgraded'));

        // Shared page survived; only the counter grew.
        $flyer->refresh();
        $this->assertSame(2, (int) $flyer->total_pages);
        $this->assertDatabaseHas('flyer_pages', ['flyer_id' => $flyer->id, 'page_number' => 1, 'image_path' => 'flyers/pages/shared-1.webp']);

        // Append batch carries ONLY the new page, numbered after the tail.
        Bus::assertBatched(function (PendingBatch $pendingBatch): bool {
            $this->assertStringContainsString('-appended', (string) $pendingBatch->name);

            $jobs = $pendingBatch->jobs->all();
            $this->assertCount(1, $jobs);
            $this->assertInstanceOf(ProcessSinglePageJob::class, $jobs[0]);
            $this->assertSame(2, $jobs[0]->pageNumber);

            return true;
        });

        // Only post B's payload was upgraded to its merged superset.
        $this->assertCount(2, $rawPostB->refresh()->image_urls ?? []);
    }

    public function test_identical_repost_of_terminal_post_confirms_duplicate_without_redispatch(): void
    {
        Queue::fake();

        $first = $this->postIngest($this->payload(['facebook_post_id' => 'dup-post-1']));
        $first->assertStatus(202);
        $this->assertTrue((bool) $first->json('queued'));

        // Simulate the Gatekeeper pipeline having completed (terminal state).
        RawFacebookPost::query()->where('facebook_post_id', 'dup-post-1')->update(['status' => 'accepted']);

        $second = $this->postIngest($this->payload(['facebook_post_id' => 'dup-post-1']));

        $second->assertStatus(202);
        $this->assertFalse((bool) $second->json('queued'));
        $this->assertTrue((bool) $second->json('duplicate'));

        // No duplicate row, no re-dispatch, no payload shrink.
        $this->assertSame(1, RawFacebookPost::query()->count());
        Queue::assertPushedTimes(GatekeeperFacebookPostJob::class, 1);
    }

    public function test_24h_text_snippet_fallback_feeds_upgrade_for_shifted_post_id(): void
    {
        Queue::fake();

        $text = 'خصومات نهاية الأسبوع على جميع السلع الغذائية بأسعار مميزة';

        $first = $this->postIngest($this->payload([
            'facebook_post_id' => 'scraper-part-1',
            'post_text' => $text,
            'image_urls' => ['https://scontent.fbcdn.net/img/album-1.jpg'],
        ]));
        $first->assertStatus(202);

        // Rotating scraper retries with a DIFFERENT facebook_post_id AND
        // different image URLs but near-identical text: must enrich the
        // ORIGINAL raw post and re-dispatch — never spawn a second row.
        $second = $this->postIngest($this->payload([
            'facebook_post_id' => 'scraper-part-2',
            'post_text' => "خصومات نهاية الأسبوع على جميع السلع\nالغذائية بأسعار مميزة",
            'image_urls' => ['https://scontent.fbcdn.net/img/album-2.jpg'],
        ]));

        $second->assertStatus(202);
        $this->assertTrue((bool) $second->json('queued'));

        $this->assertSame(1, RawFacebookPost::query()->count());

        $rawPost = RawFacebookPost::query()->first();
        $this->assertSame('scraper-part-1', $rawPost->facebook_post_id);
        $this->assertSame('pending', $rawPost->status);
        $this->assertCount(
            2,
            $rawPost->image_urls,
            'Merged superset must contain both the stored and the incoming image.',
        );

        Queue::assertPushedTimes(GatekeeperFacebookPostJob::class, 2);
    }

    public function test_blocks_ssrf_image_urls(): void
    {
        Queue::fake();

        $response = $this->postIngest($this->payload([
            'image_urls' => ['http://169.254.169.254/latest/meta-data'],
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, RawFacebookPost::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_honors_auto_ingest_kill_switch(): void
    {
        $this->retailer->update(['auto_ingest_enabled' => false]);

        Queue::fake();

        $response = $this->postIngest($this->payload());

        $response->assertStatus(202);
        $this->assertFalse((bool) $response->json('queued'));
        $this->assertSame(0, RawFacebookPost::query()->count());
        Queue::assertNothingPushed();
    }
}
