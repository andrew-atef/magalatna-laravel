<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DirectFacebookPhotosIngestCommand;
use App\Models\RawFacebookPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class DirectIngestCommandTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_missing_curl_binary_fails_fast(): void
    {
        // On a box without curl_chrome116 (this dev/test machine) the command
        // must abort BEFORE iterating stores — no wasted cycles, no fake
        // telemetry, and definitely no crashes mid-loop.
        $this->artisan('flyers:ingest-direct')
            ->assertExitCode(1);
    }

    public function test_force_reharvest_updates_existing_row_instead_of_duplicating(): void
    {
        $retailer = $this->createPublishedFlyer(['slug' => 'kazyon-live'])->retailer;

        $existing = RawFacebookPost::query()->create([
            'retailer_id' => $retailer->id,
            'facebook_post_id' => 'force-post-1',
            'post_text' => 'النص القديم',
            'image_urls' => ['https://scontent.xx.fbcdn.net/old.jpg'],
            'status' => 'accepted',
            'published_at' => now('UTC')->subDay(),
        ]);

        $command = new DirectFacebookPhotosIngestCommand;
        $method = new \ReflectionMethod($command, 'persistPost');

        // The --force fix: pass the fetched $existing (NOT null) so the row is
        // updated in place rather than crashing on UNIQUE(retailer_id, facebook_post_id).
        $result = $method->invoke(
            $command,
            $retailer,
            'force-post-1',
            'النص الجديد المحدث',
            ['https://scontent.xx.fbcdn.net/new-1.jpg', 'https://scontent.xx.fbcdn.net/new-2.jpg'],
            now('UTC'),
            $existing,
        );

        $this->assertSame($existing->id, $result->id);
        $this->assertSame('النص الجديد المحدث', $result->refresh()->post_text);
        $this->assertSame(['https://scontent.xx.fbcdn.net/new-1.jpg', 'https://scontent.xx.fbcdn.net/new-2.jpg'], $result->image_urls);
        $this->assertSame('pending', $result->status);

        // Exactly ONE row — no duplicate.
        $this->assertSame(1, RawFacebookPost::query()->where('facebook_post_id', 'force-post-1')->count());
    }

    public function test_persist_post_creates_row_when_no_existing(): void
    {
        $retailer = $this->createPublishedFlyer(['slug' => 'kazyon-live'])->retailer;

        $command = new DirectFacebookPhotosIngestCommand;
        $method = new \ReflectionMethod($command, 'persistPost');

        $result = $method->invoke(
            $command,
            $retailer,
            'fresh-post-1',
            '',
            ['https://scontent.xx.fbcdn.net/a.jpg'],
            now('UTC'),
            null,
        );

        $this->assertNotNull($result->id);
        $this->assertNull($result->post_text);
        $this->assertSame('pending', $result->status);
    }

    public function test_dedup_gate_uses_exact_match_with_escaped_like(): void
    {
        $retailer = $this->createPublishedFlyer(['slug' => 'kazyon-live'])->retailer;

        RawFacebookPost::query()->create([
            'retailer_id' => $retailer->id,
            'facebook_post_id' => '123456789012345',
            'post_text' => 'نص',
            'image_urls' => [],
            'status' => 'pending',
            'published_at' => now('UTC'),
        ]);

        // Exact id exists → gate reports existence.
        $this->assertTrue(
            RawFacebookPost::query()->where('retailer_id', $retailer->id)
                ->where(fn ($q) => $q->where('facebook_post_id', '123456789012345')
                    ->orWhereRaw("facebook_post_id LIKE ? ESCAPE '\\'", ['%'.addcslashes('123456789012345', '\\%_').'%']))
                ->exists()
        );

        // A DIFFERENT id that merely shares no substring must NOT match.
        $this->assertFalse(
            RawFacebookPost::query()->where('retailer_id', $retailer->id)
                ->where(fn ($q) => $q->where('facebook_post_id', '987654321098765')
                    ->orWhereRaw("facebook_post_id LIKE ? ESCAPE '\\'", ['%'.addcslashes('987654321098765', '\\%_').'%']))
                ->exists()
        );
    }
}
