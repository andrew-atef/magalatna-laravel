<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RegenerateFlyerContentJob;
use App\Models\Flyer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Safe AI-content regeneration for an existing flyer from SSH:
 *
 *   php artisan flyers:regenerate 42                 (full re-OCR, queued)
 *   php artisan flyers:regenerate 42 --mode=copy     (texts only, queued)
 *   php artisan flyers:regenerate 42 --sync          (run in-process)
 *
 * The SEO slug is never touched; a failed/empty full re-OCR preserves the
 * flyer's existing items (zero data loss).
 */
final class RegenerateFlyerCommand extends Command
{
    protected $signature = 'flyers:regenerate {flyer_id} {--mode=full : copy or full} {--sync : Run synchronously instead of queueing}';

    protected $description = 'إعادة توليد محتوى مجلة بالـ AI (نصوص فقط أو إعادة OCR كاملة من صور R2) بدون تغيير الـ slug وبدون فقدان للسلع عند الفشل';

    public function handle(): int
    {
        $flyerId = (int) $this->argument('flyer_id');
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['copy', 'full'], true)) {
            $this->error("Invalid mode '{$mode}' — must be 'copy' or 'full'.");

            return self::FAILURE;
        }

        $flyer = Flyer::query()->find($flyerId);

        if ($flyer === null) {
            $this->error("Flyer #{$flyerId} not found.");

            return self::FAILURE;
        }

        $this->info("Regenerating flyer #{$flyer->id} ({$flyer->slug}) — mode: {$mode}");

        try {
            if ((bool) $this->option('sync')) {
                RegenerateFlyerContentJob::dispatchSync($flyerId, $mode);
                $this->info('Done (synchronous). Cloudflare purge + sitemap/llms refresh fired via FlyerObserver.');
            } else {
                RegenerateFlyerContentJob::dispatch($flyerId, $mode);
                $this->info('Queued. The queue worker will regenerate and purge the CDN edge automatically.');
            }
        } catch (Throwable $e) {
            $this->error('Regeneration failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
