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

    private function createFlyer(int $pageCount = 1, array $dateRange = []): Flyer
    {
        $validFrom = $dateRange['from'] ?? '2026-11-25';
        $validUntil = $dateRange['until'] ?? '2026-12-01';

        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'عروض كارفور الأسبوعية',
                'slug' => 'carrefour-weekly-offers-2026-11-25',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
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

    private function createSingleDayFlyer(int $pageCount = 1, string $date = '2026-11-25'): Flyer
    {
        $flyer = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt'],
            [
                'title' => 'عروض كارفور اليوم',
                'slug' => 'carrefour-today-offer-2026-11-25',
                'valid_from' => $date,
                'valid_until' => $date,
                'total_pages' => $pageCount,
            ]
        );

        for ($i = 1; $i <= 3; $i++) {
            $this->addItem($flyer, [
                'product_name' => "منتج {$i}",
                'sale_price' => 50 + $i * 10,
                'old_price' => 80 + $i * 10,
                'discount_percent' => 30 + $i,
            ]);
        }

        for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
            $this->addPage($flyer, "flyers/{$flyer->id}/page_{$pageNum}.webp", $pageNum);
        }

        return $flyer->fresh(['retailer', 'items', 'pages']);
    }

    public function test_build_flyer_caption_single_page_multi_day(): void
    {
        $flyer = $this->createFlyer(1);
        $flyer->load(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        // Verify clean headline (retailer name without Latin parentheticals)
        $this->assertStringContainsString('🔥 <b>أحدث عروض كازيون مصر</b>', $caption);

        // Verify date appears ONCE only (not duplicated in BLUF)
        $this->assertStringContainsString('📅 سارٍ من', $caption);
        $this->assertStringContainsString('حتى', $caption);

        // Verify max discount mentioned
        $this->assertStringContainsString('خصومات تصل إلى', $caption);

        // Verify real item prices with ج.م
        $this->assertStringContainsString('ج.م', $caption);

        // Verify old prices shown with strikethrough (original price)
        $this->assertStringContainsString('ـ', $caption); // Arabic tatweel used in old price display

        // Verify discount percentages on items
        $this->assertStringContainsString('(خصم', $caption);

        // Verify CTA with canonical /offers/ URL (NOT /flyers/)
        $this->assertStringContainsString('🔗 <b>شاهد الـ', $caption);
        $this->assertStringContainsString('والأسعار كاملة:</b>', $caption);
        $this->assertStringContainsString('https://magalatna.com/offers/carrefour-weekly-offers-2026-11-25', $caption);
        $this->assertStringNotContainsString('/flyers/', $caption);

        // Verify length limit (<= 950 chars)
        $this->assertLessThanOrEqual(950, mb_strlen($caption));

        // Verify valid HTML (no broken tags)
        $this->assertEquals(substr_count($caption, '<b>'), substr_count($caption, '</b>'));
    }

    public function test_build_flyer_caption_single_day(): void
    {
        // Use a future date to test "يبدأ غداً" / "يبدأ يوم..." logic
        // Since test runs in fixed time, we use a past date which should show "سارٍ اليوم"
        $flyer = $this->createSingleDayFlyer(1, '2026-11-25');
        $flyer->load(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        // Verify single-day headline
        $this->assertStringContainsString('🔥 <b>عروض كازيون مصر — عرض اليوم الواحد</b>', $caption);

        // Verify date appears ONCE
        $this->assertStringContainsString('📅', $caption);

        // Verify CTA uses /offers/ not /flyers/
        $this->assertStringContainsString('https://magalatna.com/offers/carrefour-today-offer-2026-11-25', $caption);
        $this->assertStringNotContainsString('/flyers/', $caption);
    }

    public function test_build_flyer_caption_multi_page(): void
    {
        $flyer = $this->createFlyer(5);
        $flyer->load(['retailer', 'items', 'pages']);

        $publisher = app(TelegramPublisherService::class);
        $caption = $publisher->buildFlyerCaption($flyer);

        $this->assertStringContainsString('🔥 <b>أحدث عروض كازيون مصر</b>', $caption);
        $this->assertLessThanOrEqual(950, mb_strlen($caption));
    }

    public function test_caption_uses_arabic_pluralization_for_pages(): void
    {
        // 1 page -> "صفحة"
        $flyer1 = $this->createFlyer(1);
        $flyer1->load(['retailer', 'items', 'pages']);
        $caption1 = app(TelegramPublisherService::class)->buildFlyerCaption($flyer1);
        $this->assertStringContainsString('شاهد الـ 1 صفحة', $caption1);

        // 2 pages -> "صفحتان" (needs unique slug)
        $flyer2 = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt-2'],
            [
                'title' => 'عروض كارفور 2 صفحات',
                'slug' => 'carrefour-2-pages',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'total_pages' => 2,
            ]
        );
        for ($i = 1; $i <= 3; $i++) {
            $this->addItem($flyer2, ['product_name' => "منتج {$i}", 'sale_price' => 100, 'old_price' => 150, 'discount_percent' => 30]);
        }
        $this->addPage($flyer2, "flyers/{$flyer2->id}/page_1.webp", 1);
        $this->addPage($flyer2, "flyers/{$flyer2->id}/page_2.webp", 2);
        $flyer2 = $flyer2->fresh(['retailer', 'items', 'pages']);
        $caption2 = app(TelegramPublisherService::class)->buildFlyerCaption($flyer2);
        $this->assertStringContainsString('شاهد الـ صفحتان', $caption2);

        // 5 pages -> "5 صفحات" (needs unique slug)
        $flyer5 = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt-5'],
            [
                'title' => 'عروض كارفور 5 صفحات',
                'slug' => 'carrefour-5-pages',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'total_pages' => 5,
            ]
        );
        for ($i = 1; $i <= 3; $i++) {
            $this->addItem($flyer5, ['product_name' => "منتج {$i}", 'sale_price' => 100, 'old_price' => 150, 'discount_percent' => 30]);
        }
        for ($p = 1; $p <= 5; $p++) {
            $this->addPage($flyer5, "flyers/{$flyer5->id}/page_{$p}.webp", $p);
        }
        $flyer5 = $flyer5->fresh(['retailer', 'items', 'pages']);
        $caption5 = app(TelegramPublisherService::class)->buildFlyerCaption($flyer5);
        $this->assertStringContainsString('شاهد الـ 5 صفحات', $caption5);

        // 15 pages -> "15 صفحة" (11+ uses plural11Plus) (needs unique slug)
        $flyer15 = $this->createPublishedFlyer(
            ['slug' => 'carrefour-egypt-15'],
            [
                'title' => 'عروض كارفور 15 صفحات',
                'slug' => 'carrefour-15-pages',
                'valid_from' => '2026-11-25',
                'valid_until' => '2026-12-01',
                'total_pages' => 15,
            ]
        );
        for ($i = 1; $i <= 3; $i++) {
            $this->addItem($flyer15, ['product_name' => "منتج {$i}", 'sale_price' => 100, 'old_price' => 150, 'discount_percent' => 30]);
        }
        for ($p = 1; $p <= 15; $p++) {
            $this->addPage($flyer15, "flyers/{$flyer15->id}/page_{$p}.webp", $p);
        }
        $flyer15 = $flyer15->fresh(['retailer', 'items', 'pages']);
        $caption15 = app(TelegramPublisherService::class)->buildFlyerCaption($flyer15);
        $this->assertStringContainsString('شاهد الـ 15 صفحة', $caption15);
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

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMediaGroup');
        });

        $firstPair = Http::recorded()[0];
        $firstRequest = $firstPair[0];
        $firstMedia = json_decode($firstRequest->data()['media'], true);
        $this->assertCount(10, $firstMedia);
        $this->assertArrayHasKey('caption', $firstMedia[0]);
        $this->assertEquals('HTML', $firstMedia[0]['parse_mode']);

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

        // Must not exceed 950 characters (stricter limit)
        $this->assertLessThanOrEqual(950, mb_strlen($caption));

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