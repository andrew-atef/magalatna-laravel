<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RawFacebookIngestTest extends TestCase
{
    use RefreshDatabase;

    private Retailer $retailer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.ingest_webhook.secret' => 'test-secret',
        ]);

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

    public function test_rejects_private_ip_image_urls(): void
    {
        $response = $this->postIngest($this->payload([
            'image_urls' => ['http://169.254.169.254/latest/meta-data'],
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, RawFacebookPost::query()->count());
    }

    public function test_rejects_loopback_and_local_hostnames(): void
    {
        foreach (['http://localhost:8080/img.jpg', 'http://router.local/img.jpg', 'http://intranet/img.jpg', 'http://10.0.0.5/img.jpg', 'http://192.168.1.10/img.jpg'] as $badUrl) {
            $response = $this->postIngest($this->payload([
                'facebook_post_id' => 'post-'.uniqid(),
                'image_urls' => [$badUrl],
            ]));

            $response->assertStatus(422);
        }

        $this->assertSame(0, RawFacebookPost::query()->count());
    }

    public function test_disabled_retailer_returns_queued_false_without_creating_rows(): void
    {
        $this->retailer->update(['auto_ingest_enabled' => false]);

        Queue::fake();

        $response = $this->postIngest($this->payload());

        $response->assertStatus(202);
        $this->assertFalse($response->json('queued'));
        $this->assertSame(0, RawFacebookPost::query()->count());
        Queue::assertNotPushed(GatekeeperFacebookPostJob::class);
    }

    public function test_inactive_retailer_returns_queued_false(): void
    {
        $this->retailer->update(['is_active' => false]);

        Queue::fake();

        $response = $this->postIngest($this->payload());

        $response->assertStatus(202);
        $this->assertFalse($response->json('queued'));
        Queue::assertNotPushed(GatekeeperFacebookPostJob::class);
    }

    public function test_valid_post_is_persisted_and_gatekeeper_dispatched(): void
    {
        Queue::fake();

        $response = $this->postIngest($this->payload());

        $response->assertStatus(202);
        $this->assertTrue($response->json('queued'));

        $this->assertSame(1, RawFacebookPost::query()->count());
        $this->assertSame('pending', RawFacebookPost::query()->first()->status);

        Queue::assertPushed(GatekeeperFacebookPostJob::class);
    }

    public function test_deduplicates_identical_multiline_posts_within_24h(): void
    {
        Queue::fake();

        $text = "عرض خاص\n\nخصومات على الزيت\nوالأرز";

        $first = $this->postIngest($this->payload(['post_text' => $text]));
        $first->assertStatus(202);
        $this->assertTrue($first->json('queued'));

        // Same content but with different whitespace/newlines — must still dedup.
        $second = $this->postIngest($this->payload([
            'post_text' => "عرض خاص\r\n خصومات على الزيت والحرز", // deliberately different char
        ]));
        // Different text → NOT a duplicate (separate post id).
        $second->assertStatus(202);

        $third = $this->postIngest($this->payload(['post_text' => "عرض خاص   \nخصومات على الزيت\nوالأرز"]));

        $third->assertStatus(202);
        $this->assertTrue((bool) $third->json('duplicate'));
        $this->assertFalse((bool) $third->json('queued'));
    }

    public function test_deduplicates_image_only_posts_by_image_path(): void
    {
        Queue::fake();

        $image = 'https://scontent.fbcdn.net/img/photo-album.jpg?v=abc&oh=1';

        $first = $this->postIngest($this->payload([
            'facebook_post_id' => 'imgpost-1',
            'post_text' => '',
            'image_urls' => [$image],
        ]));
        $first->assertStatus(202);
        $this->assertTrue($first->json('queued'));

        // Same image path but a DIFFERENT query string + different post id:
        // content fingerprint must dedup (scraper fallback hashes shift).
        $second = $this->postIngest($this->payload([
            'facebook_post_id' => 'imgpost-2',
            'post_text' => '',
            'image_urls' => ['https://scontent.fbcdn.net/img/photo-album.jpg?v=zzz&oh=9'],
        ]));

        $second->assertStatus(202);
        $this->assertTrue((bool) $second->json('duplicate'));

        $this->assertSame(1, RawFacebookPost::query()->count());
        Queue::assertPushedTimes(GatekeeperFacebookPostJob::class, 1);
    }

    public function test_terminal_status_post_is_never_overwritten_or_redispatched(): void
    {
        $originalText = 'النص الأصلي المعتمد إدارياً';

        RawFacebookPost::query()->create([
            'retailer_id' => $this->retailer->id,
            'facebook_post_id' => 'terminal-1',
            'post_text' => $originalText,
            'image_urls' => ['https://scontent.fbcdn.net/img/original.jpg'],
            'status' => 'accepted',
        ]);

        Queue::fake();

        $response = $this->postIngest($this->payload([
            'facebook_post_id' => 'terminal-1',
            'post_text' => 'نص متغير من السكرابر',
            'image_urls' => ['https://scontent.fbcdn.net/img/changed.jpg'],
        ]));

        $response->assertStatus(202);
        $this->assertFalse((bool) $response->json('queued'));

        $row = RawFacebookPost::query()->where('facebook_post_id', 'terminal-1')->first();
        $this->assertSame($originalText, $row->post_text);
        $this->assertSame('accepted', $row->status);

        Queue::assertNotPushed(GatekeeperFacebookPostJob::class);
    }

    public function test_500_response_does_not_leak_exception_message(): void
    {
        $secret = 'leaked-sqlite-path C:\db\database.sqlite';
        RawFacebookPost::creating(static function () use ($secret): never {
            throw new \RuntimeException("boom {$secret}");
        });

        try {
            $response = $this->postIngest($this->payload());

            $response->assertStatus(500);
            $this->assertArrayNotHasKey('error', (array) $response->json());
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString('boom', $response->getContent());
            $this->assertSame('Failed to queue post.', $response->json('message'));
        } finally {
            RawFacebookPost::flushEventListeners();
        }
    }

    public function test_lock_prevents_concurrent_double_dispatch(): void
    {
        Cache::lock("ingest_post:{$this->retailer->id}:locked-post", 10)->get();

        Queue::fake();

        $response = $this->postIngest($this->payload(['facebook_post_id' => 'locked-post']));

        $response->assertStatus(202);
        $this->assertFalse((bool) $response->json('queued'));
        Queue::assertNotPushed(GatekeeperFacebookPostJob::class);
        $this->assertSame(0, RawFacebookPost::query()->count());
    }

    public function test_fingerprint_cache_contract(): void
    {
        // Sanity: cache-array store supports the fingerprint key contract.
        $this->assertTrue(Cache::add('ingest_fp:test', 'x', now()->addMinutes(1)));
        $this->assertFalse(Cache::add('ingest_fp:test', 'y', now()->addMinutes(1)));
        $this->assertSame('x', Cache::get('ingest_fp:test'));
        DB::connection()->getPdo(); // connection boots fine
    }
}
