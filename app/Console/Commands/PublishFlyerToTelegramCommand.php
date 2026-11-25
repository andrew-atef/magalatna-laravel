<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PublishFlyerToTelegramJob;
use App\Models\Flyer;
use App\Services\TelegramPublisherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PublishFlyerToTelegramCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flyers:publish-telegram {flyer_id} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish a flyer to Telegram channel/group. Use --force to bypass idempotency check.';

    public function handle(TelegramPublisherService $publisher): int
    {
        $flyerId = (int) $this->argument('flyer_id');
        $force = (bool) $this->option('force');

        $flyer = Flyer::with(['retailer', 'items', 'pages' => fn ($q) => $q->orderBy('page_number')])
            ->find($flyerId);

        if ($flyer === null) {
            $this->error("Flyer #{$flyerId} not found.");
            return Command::FAILURE;
        }

        if ($flyer->status !== \App\Enums\FlyerStatus::Published) {
            $this->warn("Flyer #{$flyerId} is not published (status: {$flyer->status->value}).");
            if (! $force) {
                $this->error('Use --force to publish anyway.');
                return Command::FAILURE;
            }
        }

        $validItemsCount = $flyer->items()->where('sale_price', '>', 0)->count();
        if ($validItemsCount === 0) {
            $this->warn("Flyer #{$flyerId} has no priced items.");
            if (! $force) {
                $this->error('Use --force to publish anyway.');
                return Command::FAILURE;
            }
        }

        $pagesCount = $flyer->pages()->count();
        if ($pagesCount === 0) {
            $this->warn("Flyer #{$flyerId} has no pages.");
            if (! $force) {
                $this->error('Use --force to publish anyway.');
                return Command::FAILURE;
            }
        }

        // Show preview of caption
        $caption = $publisher->buildFlyerCaption($flyer);
        $this->info("Flyer: {$flyer->title}");
        $this->info("Retailer: {$flyer->retailer?->name}");
        $this->info("Pages: {$pagesCount}");
        $this->info("Priced Items: {$validItemsCount}");
        $this->newLine();
        $this->info('Caption preview:');
        $this->line($caption);
        $this->newLine();

        if (! $force && ! $this->confirm('Proceed with publishing to Telegram?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        try {
            $this->info('Publishing to Telegram...');
            $sentMessageIds = $publisher->publishFlyer($flyer);

            $this->info('Successfully published to Telegram!');
            $this->info('Message IDs: '.implode(', ', array_map('strval', $sentMessageIds)));
            $this->info('Photos sent: '.count($sentMessageIds));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('PublishFlyerToTelegramCommand failed.', [
                'flyer_id' => $flyerId,
                'error' => $e->getMessage(),
            ]);

            $this->error('Failed to publish: '.$e->getMessage());
            return Command::FAILURE;
        }
    }
}