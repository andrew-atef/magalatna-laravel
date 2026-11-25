<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Flyer;
use App\Models\FlyerTelegramPost;
use App\Services\TelegramPublisherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublishFlyerToTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public int $timeout = 120;

    public function __construct(
        public readonly int $flyerId,
        public readonly bool $force = false,
    ) {}

    public function handle(TelegramPublisherService $publisher): void
    {
        $flyer = Flyer::with(['retailer', 'items', 'pages' => fn ($q) => $q->orderBy('page_number')])
            ->find($this->flyerId);

        if ($flyer === null) {
            Log::warning('PublishFlyerToTelegramJob: Flyer not found.', ['flyer_id' => $this->flyerId]);
            return;
        }

        // Guard: must be Published with items > 0 and pages > 0
        if ($flyer->status !== \App\Enums\FlyerStatus::Published) {
            Log::info('PublishFlyerToTelegramJob: Flyer not published, skipping.', [
                'flyer_id' => $flyer->id,
                'status' => $flyer->status->value,
            ]);
            return;
        }

        $validItemsCount = $flyer->items()->where('sale_price', '>', 0)->count();
        if ($validItemsCount === 0) {
            Log::info('PublishFlyerToTelegramJob: Flyer has no priced items, skipping.', [
                'flyer_id' => $flyer->id,
            ]);
            return;
        }

        $pagesCount = $flyer->pages()->count();
        if ($pagesCount === 0) {
            Log::info('PublishFlyerToTelegramJob: Flyer has no pages, skipping.', [
                'flyer_id' => $flyer->id,
            ]);
            return;
        }

        // Idempotency: unless $force is true, check if already published
        if (! $this->force) {
            $existing = FlyerTelegramPost::where('flyer_id', $flyer->id)
                ->where('status', 'published')
                ->exists();

            if ($existing) {
                Log::info('PublishFlyerToTelegramJob: Already published, skipping (idempotent).', [
                    'flyer_id' => $flyer->id,
                ]);
                return;
            }
        }

        // Create/update record with status = 'pending'
        $telegramPost = FlyerTelegramPost::updateOrCreate(
            ['flyer_id' => $flyer->id],
            [
                'chat_id' => config('services.telegram.chat_id'),
                'status' => 'pending',
                'caption' => '',
                'photo_count' => 0,
                'message_ids' => null,
                'error_message' => null,
                'published_at' => null,
            ]
        );

        try {
            $caption = $publisher->buildFlyerCaption($flyer);
            $sentMessageIds = $publisher->publishFlyer($flyer);

            $telegramPost->update([
                'status' => 'published',
                'message_ids' => $sentMessageIds,
                'photo_count' => $pagesCount,
                'caption' => $caption,
                'published_at' => now(),
                'error_message' => null,
            ]);

            Log::info('PublishFlyerToTelegramJob: Successfully published to Telegram.', [
                'flyer_id' => $flyer->id,
                'message_ids' => $sentMessageIds,
                'photo_count' => $pagesCount,
            ]);
        } catch (Throwable $e) {
            $telegramPost->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('PublishFlyerToTelegramJob: Failed to publish to Telegram.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e; // Re-throw to trigger retry with backoff
        }
    }
}