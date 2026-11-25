<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendFlyerPushNotificationJob;
use App\Models\Flyer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PushFlyerNotificationCommand extends Command
{
    protected $signature = 'flyers:push {flyer_id} {--force : Force send even if already pushed or within global cooldown}';
    protected $description = 'Send OneSignal push notification for a published flyer';

    public function handle(): int
    {
        $flyerId = (int) $this->argument('flyer_id');
        $force = (bool) $this->option('force');

        $flyer = Flyer::find($flyerId);

        if ($flyer === null) {
            $this->error("Flyer #{$flyerId} not found.");
            return Command::FAILURE;
        }

        if ($flyer->status !== \App\Enums\FlyerStatus::Published) {
            $this->error("Flyer #{$flyerId} is not published (status: {$flyer->status->value}).");
            return Command::FAILURE;
        }

        if ($flyer->isExpired()) {
            $this->error("Flyer #{$flyerId} is expired.");
            return Command::FAILURE;
        }

        $validItems = $flyer->items()->where('sale_price', '>', 0)->count();
        if ($validItems === 0) {
            $this->error("Flyer #{$flyerId} has no priced items.");
            return Command::FAILURE;
        }

        $this->info("Queueing push notification for flyer #{$flyerId} ({$flyer->title})");
        $this->line("Status: {$flyer->status->value}");
        $this->line("Items with price: {$validItems}");
        $this->line("Push sent at: " . ($flyer->push_sent_at?->format('Y-m-d H:i') ?? 'never'));
        $this->line("Force mode: " . ($force ? 'YES (bypasses cooldown & idempotency)' : 'NO (respects cooldown & idempotency)'));

        if (! $force && $flyer->push_sent_at !== null) {
            if (! $this->confirm("This flyer was already pushed at {$flyer->push_sent_at->format('Y-m-d H:i')}. Send anyway?")) {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        SendFlyerPushNotificationJob::dispatch($flyerId, $force);

        $this->info("Job dispatched. Check queue worker logs for delivery status.");
        Log::info('[CLI] flyers:push dispatched', ['flyer_id' => $flyerId, 'force' => $force]);

        return Command::SUCCESS;
    }
}