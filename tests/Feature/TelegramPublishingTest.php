<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Jobs\PublishFlyerToTelegramJob;
use App\Models\Flyer;
use App\Models\FlyerPage;
use App\Models\FlyerTelegramPost;
use App\Models\Retailer;
use App\Services\TelegramPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class TelegramPublishingTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test_bot_token_123456789:ABCdefGHIjklMNOpqrsTUVwxyz',
            'services.telegram.chat_id' => '@testchannel',
            'services.telegram.auto_publish' => true,
            'services.telegram.timeout' => 30,
            'app.url' => 'https://magalatna.com',
        ]);
    }

    private function createFlyer(int $pageCount = 1): Flyer
    {
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'عروض كارفور الأسبوعية',
                'slug' => 'carrefour-weekly-offers-2026-11-25',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'total_pages' => $pageCount,
            ]
        );

        // Create items with prices and discounts
        for ($i = 1; $i <= 5; $i++) {
            $this->addItem($flyer, [
                'product_name' => "منتج اختبار {$i}",
                'sale_price' => 100 + $i * 10,
                'old_price' => 150 + $i * 10,
                'discount_percent' => 20 + $i,
            ]);
        }

        // Create pages with image paths
        for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
            $this->addPage($flyer, "flyers/{$flyer->id}/page_{$pageNum}.webp", $pageNum);
        }

        return $flyer->fresh(['retailer', 'items', 'pages']);
    }

    public function test_build_flyer_caption_single_page(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        // Verify caption structure
        $this->assertStringContainsString('<b>عروض كارفور الأسبوعية</b>', $caption);
        $this->assertStringContainsString('📅 سارٍ من', $caption);
        $this->assertStringContainsString('بتوقيت القاهرة', $caption);
        $this->assertStringContainsString('بخصومات تصل إلى', $caption);
        $this->assertStringContainsString('🔗 <b>تصفح أسعار السلع وقارنها بالكامل:</b>', $caption);
        $this->assertStringContainsString('https://magalatna.com/flyers/carrefour-weekly-offers-2026-11-25', $caption);

        // Verify length limit (<= 1000 chars)
        $this->assertLessThanOrEqual(1000, mb_strlen($caption));

        // Verify HTML escaping - no raw unescaped HTML tags
        $this->assertStringNotContainsString('<script', $caption);
    }

    public function test_build_flyer_caption_multi_page(): void
    {
        $flyer = $this->createFlyer(5);
        $flyer->load(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        $this->assertStringContainsString('<b>عروض كارفور الأسبوعية</b>', $caption);
        $this->assertLessThanOrEqual(1000, mb_strlen($caption));
    }

    public function test_publish_single_page_flyer_uses_sendphoto(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        Http::fake([
            'api.telegram.org/bot*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 12345,
                    'chat' => ['id' => -1001234567890],
                    'date' => time(),
                ],
            ], 200),
        ]);

        $publisher = app(TelegramPublisherService::class);
        $messageIds = $publisher->publishFlyer($flyer);

        $this->assertCount(1, $messageIds);
        $this->assertEquals(12345, $messageIds[0]);

        // Verify the API call was made to sendPhoto
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendPhoto')
                && $request->data()['chat_id'] === '@testchannel'
                && $request->data()['parse_mode'] === 'HTML';
        });
    }

    public function test_publish_multi_page_flyer_uses_sendmediagroup_chunked(): void
    {
        $flyer = $this->createFlyer(15); // 15 pages = 2 chunks (10 + 5)
        $flyer->load(['retailer', 'items', 'pages']);

        Http::fake([
            'api.telegram.org/bot*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => array_map(fn ($i) => ['message_id' => 1000 + $i], range(1, 10)),
                ], 200)
                ->push([
                    'ok' => true,
                    'result' => array_map(fn ($i) => ['message_id' => 2000 + $i], range(1, 5)),
                ], 200),
        ]);

        $publisher = app(TelegramPublisherService::class);
        $messageIds = $publisher->publishFlyer($flyer);

        $this->assertCount(15, $messageIds);

        // Verify two sendMediaGroup calls were made
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMediaGroup');
        });

        // First call should have 10 items with caption on first
        $firstPair = Http::recorded()[0];
        $firstRequest = $firstPair[0];
        $firstMedia = json_decode($firstRequest->data()['media'], true);
        $this->assertCount(10, $firstMedia);
        $this->assertArrayHasKey('caption', $firstMedia[0]);
        $this->assertEquals('HTML', $firstMedia[0]['parse_mode']);

        // Second call should have 5 items with helper caption on first
        $secondPair = Http::recorded()[1];
        $secondRequest = $secondPair[0];
        $secondMedia = json_decode($secondRequest->data()['media'], true);
        $this->assertCount(5, $secondMedia);
        $this->assertArrayHasKey('caption', $secondMedia[0]);
        $this->assertStringContainsString('تابع باقي الصفحات', $secondMedia[0]['caption']);
    }

    public function test_caption_truncation_at_word_boundary(): void
    {
        // Create flyer with very long title and many items
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => str_repeat('عرض طويل جداً جداً جداً ', 10),
                'slug' => 'very-long-title-test',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'total_pages' => 1,
            ]
        );

        // Add many items with long names
        for ($i = 1; $i <= 10; $i++) {
            $this->addItem($flyer, [
                'product_name' => str_repeat("منتج رقم {$i} باسم طويل جداً جداً ", 5),
                'sale_price' => 100,
                'old_price' => 200,
                'discount_percent' => 50,
            ]);
        }

        $this->addPage($flyer, "flyers/{$flyer->id}/page_1.webp", 1);

        $flyer = $flyer->fresh(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        // Must not exceed 1000 characters
        $this->assertLessThanOrEqual(1000, mb_strlen($caption));

        // Must not have broken HTML tags - count opening and closing tags
        $this->assertEquals(substr_count($caption, '<b>'), substr_count($caption, '</b>'));
    }

    public function test_idempotency_prevents_double_post(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        Http::fake([
            'api.telegram.org/bot*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 12345,
                ],
            ], 200),
        ]);

        // First publish
        $job = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job->handle(app(TelegramPublisherService::class));

        // Verify record created
        $record = FlyerTelegramPost::where('flyer_id', $flyer->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('published', $record->status);

        // Second publish (should be skipped due to idempotency)
        $job2 = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job2->handle(app(TelegramPublisherService::class));

        // Should still only have one record
        $this->assertEquals(1, FlyerTelegramPost::where('flyer_id', $flyer->id)->count());
    }

    public function test_force_flag_bypasses_idempotency(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        Http::fake([
            'api.telegram.org/bot*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 12345,
                ],
            ], 200),
        ]);

        // First publish
        $job = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job->handle(app(TelegramPublisherService::class));

        // Force publish again
        $job2 = new PublishFlyerToTelegramJob($flyer->id, force: true);
        $job2->handle(app(TelegramPublisherService::class));

        // Should have updated the same record (not created new one)
        $this->assertEquals(1, FlyerTelegramPost::where('flyer_id', $flyer->id)->count());
    }

    public function test_failure_records_error_and_retries(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        Http::fake([
            'api.telegram.org/bot*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $job = new PublishFlyerToTelegramJob($flyer->id, force: true);

        $this->expectException(\RuntimeException::class);
        $job->handle(app(TelegramPublisherService::class));

        $record = FlyerTelegramPost::where('flyer_id', $flyer->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('failed', $record->status);
        $this->assertStringContainsString('chat not found', $record->error_message);
    }

    public function test_skips_non_published_flyer(): void
    {
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'Draft Flyer',
                'slug' => 'draft-flyer',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'status' => FlyerStatus::Draft,
                'total_pages' => 1,
            ]
        );

        $this->addItem($flyer, [
            'product_name' => 'منتج',
            'sale_price' => 100,
            'old_price' => 200,
            'discount_percent' => 50,
        ]);

        $this->addPage($flyer, "flyers/{$flyer->id}/page_1.webp", 1);

        $job = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job->handle(app(TelegramPublisherService::class));

        // Should not create any record for non-published flyer
        $this->assertEquals(0, FlyerTelegramPost::where('flyer_id', $flyer->id)->count());
    }

    public function test_skips_flyer_with_no_pages(): void
    {
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'No Pages Flyer',
                'slug' => 'no-pages-flyer',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'status' => FlyerStatus::Published,
                'total_pages' => 0,
            ]
        );

        $this->addItem($flyer, [
            'product_name' => 'منتج',
            'sale_price' => 100,
            'old_price' => 200,
            'discount_percent' => 50,
        ]);

        $job = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job->handle(app(TelegramPublisherService::class));

        $this->assertEquals(0, FlyerTelegramPost::where('flyer_id', $flyer->id)->count());
    }

    public function test_skips_flyer_with_no_priced_items(): void
    {
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'No Priced Items',
                'slug' => 'no-priced-items',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'status' => FlyerStatus::Published,
                'total_pages' => 1,
            ]
        );

        $this->addItem($flyer, [
            'product_name' => 'منتج بدون سعر',
            'sale_price' => 0,
            'old_price' => 0,
            'discount_percent' => 0,
        ]);

        $this->addPage($flyer, "flyers/{$flyer->id}/page_1.webp", 1);

        $job = new PublishFlyerToTelegramJob($flyer->id, force: false);
        $job->handle(app(TelegramPublisherService::class));

        $this->assertEquals(0, FlyerTelegramPost::where('flyer_id', $flyer->id)->count());
    }
}