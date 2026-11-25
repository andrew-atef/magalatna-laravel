<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Flyer;
use App\Services\OneSignalPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class SendFlyerPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public readonly int $flyerId,
        public readonly bool $force = false,
    ) {}

    public function handle(OneSignalPushService $oneSignal): void
    {
        $flyer = Flyer::find($this->flyerId);

        if ($flyer === null) {
            Log::warning('[PUSH] Flyer not found', ['flyer_id' => $this->flyerId]);
            return;
        }

        // Guards
        if ($flyer->status !== \App\Enums\FlyerStatus::Published) {
            Log::info('[PUSH] Flyer not published, skipping', ['flyer_id' => $flyer->id, 'status' => $flyer->status->value]);
            return;
        }

        if ($flyer->isExpired()) {
            Log::info('[PUSH] Flyer expired, skipping', ['flyer_id' => $flyer->id]);
            return;
        }

        $validItemsCount = $flyer->items()->where('sale_price', '>', 0)->count();
        if ($validItemsCount === 0) {
            Log::info('[PUSH] Flyer has no priced items, skipping', ['flyer_id' => $flyer->id]);
            return;
        }

        // Idempotency: atomically claim the flyer unless forced
        if (! $this->force) {
            $claimed = DB::table('flyers')
                ->whereKey($this->flyerId)
                ->whereNull('push_sent_at')
                ->update(['push_sent_at' => now()]);

            if ($claimed === 0) {
                Log::info('[PUSH] Flyer already pushed (push_sent_at set), skipping', ['flyer_id' => $flyer->id]);
                return;
            }
        }

        // Global anti-spam throttle (15 min default)
        if (! $this->force) {
            $cooldownMinutes = (int) config('services.onesignal.cooldown_minutes', 15);
            $cooldownKey = 'onesignal_global_push_cooldown';

            if (! Cache::add($cooldownKey, true, now()->addMinutes($cooldownMinutes))) {
                // Already pushed within cooldown window — release job to retry later
                Log::info('[PUSH] Global cooldown active, releasing job', [
                    'flyer_id' => $flyer->id,
                    'cooldown_minutes' => $cooldownMinutes,
                ]);
                $this->release($cooldownMinutes * 60);
                return;
            }
        }

        // Send the push
        try {
            $oneSignal->sendFlyerNotification($flyer);
        } catch (Throwable $e) {
            // On failure, reset push_sent_at if not forced (so it can retry)
            if (! $this->force) {
                DB::table('flyers')->whereKey($this->flyerId)->update(['push_sent_at' => null]);
            }
            Log::error('[PUSH] Failed to send flyer notification', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}