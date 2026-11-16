<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireOldFlyersCommand extends Command
{
    protected $signature = 'flyers:expire';

    protected $description = 'تحويل المجلات والعروض المنتهية الصلاحية بتوقيت القاهرة إلى حالة expired';

    public function handle(): int
    {
        // Cairo date boundary: a flyer expires only after its valid_until DAY
        // has fully elapsed in Africa/Cairo (consistent with Flyer::isExpired()).
        $cairoToday = Carbon::now('Africa/Cairo')->startOfDay()->toDateString();

        $flyers = Flyer::with('retailer')
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '<', $cairoToday)
            ->get();

        if ($flyers->isEmpty()) {
            $this->info('لا توجد مجلات منتهية للتحويل اليوم.');
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            return self::SUCCESS;
        }

        $expiredIds = [];

        try {
            // Single atomic transaction: any failure rolls back ALL status flips.
            // Quiet saves: FlyerObserver::saved is suppressed so the observer does
            // NOT dispatch per-flyer purges — the command below performs the ONE
            // deduplicated, chunked purge dispatch for the whole batch.
            DB::transaction(function () use ($flyers, &$expiredIds): void {
                foreach ($flyers as $flyer) {
                    $flyer->status = FlyerStatus::Expired;
                    $flyer->saveQuietly();
                    $expiredIds[] = (int) $flyer->id;
                }
            });
        } catch (Throwable $e) {
            Log::error('ExpireOldFlyersCommand: transaction rolled back, no flyer expired.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('فشلت الأرشفة وتم التراجع عن كل التغييرات: '.$e->getMessage());

            return self::FAILURE;
        }

        $affected = count($expiredIds);

        // Collect purge URLs for the expired flyers (re-read inside fresh state).
        // ALL URLs are built from config('app.url') + RELATIVE routes —
        // request-bound url('/') / absolute route() on the CLI resolves to
        // 127.0.0.1 or the admin host and would purge the wrong cache entries.
        $base = rtrim((string) config('app.url'), '/');

        $allUrls = [];
        $expired = Flyer::with('retailer')->whereIn('id', $expiredIds)->get();
        foreach ($expired as $flyer) {
            $allUrls[] = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

            if ($flyer->retailer !== null && (string) $flyer->retailer->slug !== '') {
                $allUrls[] = $base.route('retailers.show', ['retailer' => (string) $flyer->retailer->slug], false);
            }
        }

        // Shared artifacts: homepage, sitemap and llms.txt always change when
        // flyers expire. NO IndexNow — expired URLs are NOT indexable pages.
        $allUrls[] = $base.'/';
        $allUrls[] = $base.'/sitemap.xml';
        $allUrls[] = $base.'/llms.txt';

        $allUrls = array_values(array_unique(array_filter(array_map(static fn (mixed $u): string => trim((string) $u), $allUrls))));

        Cache::forget('sitemap_xml_content');
        Cache::forget('llms_txt_content');

        // Respect Cloudflare Edge API batch limits: max 30 URLs per purge job.
        foreach (array_chunk($allUrls, 30) as $index => $chunk) {
            try {
                PurgeCloudflareCacheJob::dispatch($chunk);
            } catch (Throwable $e) {
                Log::error('ExpireOldFlyersCommand: failed to dispatch purge chunk.', [
                    'chunk' => $index,
                    'urls_count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ExpireOldFlyersCommand: expired flyers archived.', [
            'affected' => $affected,
            'urls_count' => count($allUrls),
            'chunks' => (int) ceil(count($allUrls) / 30),
        ]);

        $this->info("تمت أرشفة وتحديث {$affected} مجلة منتهية الصلاحية.");

        return self::SUCCESS;
    }
}
