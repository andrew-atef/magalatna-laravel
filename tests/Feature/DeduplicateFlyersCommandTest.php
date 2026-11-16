<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class DeduplicateFlyersCommandTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_merge_relinks_raw_posts_and_never_flushes_cache(): void
    {
        Queue::fake();

        $primary = $this->createPublishedFlyer([], ['slug' => 'primary-offer', 'title' => 'المجلة الأساسية']);
        $retailer = $primary->retailer;

        // Duplicate: same retailer + identical period (the command's grouping key).
        $dup = Flyer::query()->create([
            'retailer_id' => $retailer->id,
            'title' => 'المجلة المكررة',
            'slug' => 'dup-offer',
            'valid_from' => $primary->valid_from,
            'valid_until' => $primary->valid_until,
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ]);

        $this->addPage($dup, 'flyers/pages/dup-unique-page.webp', 1);
        $dup->items()->create([
            'product_name' => 'منتج فريد من المكررة',
            'slug' => 'unique-item-'.uniqid(),
            'sale_price' => 55.00,
            'normalized_name' => 'منتج فريد من المكررة',
        ]);

        $rawPost = RawFacebookPost::query()->create([
            'retailer_id' => $retailer->id,
            'facebook_post_id' => 'dedup-post-1',
            'post_text' => 'نص',
            'image_urls' => [],
            'status' => 'accepted',
            'flyer_id' => $dup->id,
            'published_at' => now('UTC'),
        ]);

        // Sentinels: Cache::flush() would wipe the lock sentinel; the targeted
        // forgets must clear exactly the SEO/layout artifacts.
        Cache::put('dedup_lock_sentinel', 'alive', 600);
        Cache::put('sitemap_xml_content', '<old/>', 600);
        Cache::put('llms_txt_content', 'old llms', 600);
        Cache::put('layout:header_retailers', collect(), 600);

        $this->artisan('flyers:deduplicate')->assertSuccessful();

        // 1) Raw post re-linked to the primary BEFORE the dup shell vanished.
        $this->assertSame($primary->id, $rawPost->refresh()->flyer_id);
        $this->assertDatabaseMissing('flyers', ['id' => $dup->id]);

        // 2) Unique page + item survived on the primary.
        $this->assertDatabaseHas('flyer_pages', ['flyer_id' => $primary->id, 'image_path' => 'flyers/pages/dup-unique-page.webp']);
        $this->assertDatabaseHas('flyer_items', ['flyer_id' => $primary->id, 'product_name' => 'منتج فريد من المكررة']);

        // 3) Cache::flush() was NOT called — the sentinel lock survived.
        $this->assertSame('alive', Cache::get('dedup_lock_sentinel'));

        // 4) The three targeted keys WERE invalidated.
        $this->assertNull(Cache::get('sitemap_xml_content'));
        $this->assertNull(Cache::get('llms_txt_content'));
        $this->assertNull(Cache::get('layout:header_retailers'));

        // 5) Purge used canonical config URLs (sitemap + llms + homepage),
        //    never url('/') (which resolves to http://localhost on the CLI).
        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            return in_array('https://magalatna.com/sitemap.xml', $job->urls, true)
                && in_array('https://magalatna.com/llms.txt', $job->urls, true)
                && in_array('https://magalatna.com/', $job->urls, true)
                && in_array('https://magalatna.com/offers/primary-offer', $job->urls, true);
        });
    }
}
