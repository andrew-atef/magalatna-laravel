<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FlyerPage;
use App\Services\ImageOptimizerService;
use App\Support\R2Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class Phase3ThumbnailsTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'filesystems.disks.r2.url' => 'https://cdn.magalatna.com',
        ]);
    }

    public function test_card_thumb_prefers_thumb_then_master_then_placeholder(): void
    {
        $page = new FlyerPage([
            'flyer_id' => 1,
            'page_number' => 1,
            'image_path' => 'flyers/1/master.webp',
            'thumb_path' => 'flyers/1/thumb_abc.webp',
        ]);

        $this->assertSame('https://cdn.magalatna.com/flyers/1/thumb_abc.webp', R2Url::cardThumb($page));

        // No thumbnail yet → clean fallback to the master image.
        $page->thumb_path = null;
        $this->assertSame('https://cdn.magalatna.com/flyers/1/master.webp', R2Url::cardThumb($page));

        // Nothing usable at all → local placeholder, never a blank src.
        $page->image_path = null;
        $this->assertSame('/img/placeholder-flyer.png', R2Url::cardThumb($page));

        // Missing page → placeholder.
        $this->assertSame('/img/placeholder-flyer.png', R2Url::cardThumb(null));
    }

    public function test_generate_thumb_webp_downscales_master_to_400px(): void
    {
        $manager = new ImageManager(new GdDriver);
        $image = $manager->create(1200, 1600)->fill('2b6cb0');
        $masterBinary = (string) $image->toWebp(quality: 80);

        $thumb = app(ImageOptimizerService::class)->generateThumbWebp($masterBinary);

        $this->assertNotSame('', $thumb);

        $info = getimagesizefromstring($thumb);
        $this->assertIsArray($info);
        $this->assertSame(400, $info[0], 'Thumbnail width must be exactly 400px.');
        $this->assertSame(533, $info[1], '3:4 aspect ratio must be preserved (400x533).');
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_generate_thumb_webp_never_upscales_small_masters(): void
    {
        $manager = new ImageManager(new GdDriver);
        $image = $manager->create(300, 400)->fill('7f1d1d');
        $masterBinary = (string) $image->toWebp(quality: 80);

        $thumb = app(ImageOptimizerService::class)->generateThumbWebp($masterBinary);

        $info = getimagesizefromstring($thumb);
        $this->assertIsArray($info);
        $this->assertSame(300, $info[0], 'Narrow masters keep native size — no upscaling.');
        $this->assertSame(400, $info[1]);
    }

    public function test_deleting_flyer_reaps_both_master_and_thumb_r2_objects(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/1/master.webp', 'master-bytes');
        Storage::disk('r2')->put('flyers/1/thumb_abc.webp', 'thumb-bytes');

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'thumb-cleanup-offer']);
        $page = $this->addPage($flyer, 'flyers/1/master.webp');
        $page->forceFill(['thumb_path' => 'flyers/1/thumb_abc.webp'])->saveQuietly();

        Queue::fake();

        $flyer->delete();

        // Both R2 objects are reaped by queued closures (afterCommit).
        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        Storage::disk('r2')->assertMissing('flyers/1/master.webp');
        Storage::disk('r2')->assertMissing('flyers/1/thumb_abc.webp');
    }

    public function test_replacing_thumb_path_queues_old_thumb_delete(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/1/thumb_old.webp', 'old-thumb-bytes');

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'thumb-swap-offer']);
        $page = $this->addPage($flyer, 'flyers/1/master.webp');
        $page->forceFill(['thumb_path' => 'flyers/1/thumb_old.webp'])->saveQuietly();

        Queue::fake();

        $page->thumb_path = 'flyers/1/thumb_new.webp';
        $page->save();

        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        Storage::disk('r2')->assertMissing('flyers/1/thumb_old.webp');
    }

    public function test_replacing_master_image_without_thumb_touch_leaves_no_stale_thumb(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('flyers/1/master-old.webp', 'old-master-bytes');
        Storage::disk('r2')->put('flyers/1/thumb_old.webp', 'old-thumb-bytes');

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'master-swap-offer']);
        $page = $this->addPage($flyer, 'flyers/1/master-old.webp');
        $page->forceFill(['thumb_path' => 'flyers/1/thumb_old.webp'])->saveQuietly();

        Queue::fake();

        // Filament re-upload: master swaps, thumb_path column untouched — the
        // old thumb visually belongs to the old master and must be reaped too.
        $page->image_path = 'flyers/1/master-new.webp';
        $page->save();

        Queue::pushed(CallQueuedClosure::class)->each(fn (CallQueuedClosure $job) => $job->handle(app()));

        Storage::disk('r2')->assertMissing('flyers/1/master-old.webp');
        Storage::disk('r2')->assertMissing('flyers/1/thumb_old.webp');
    }

    public function test_home_cards_render_thumbnail_url_with_400px_dimensions(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'home-thumb-offer']);
        $page = $this->addPage($flyer, 'flyers/1/master.webp');
        $page->forceFill(['thumb_path' => 'flyers/1/thumb_home.webp'])->saveQuietly();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('https://cdn.magalatna.com/flyers/1/thumb_home.webp', $html);
        $this->assertStringContainsString('width="400"', $html);
        $this->assertStringContainsString('height="533"', $html);
        $this->assertStringNotContainsString('flyers/1/master.webp', $html);
    }

    public function test_home_cards_fall_back_to_master_when_thumb_missing(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'home-fallback-offer']);
        $this->addPage($flyer, 'flyers/3/master.webp');

        $html = (string) $this->get('/')->assertOk()->getContent();

        // No thumbnail exists yet — the master image URL renders instead.
        $this->assertStringContainsString('https://cdn.magalatna.com/flyers/3/master.webp', $html);
    }
}
