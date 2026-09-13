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

            return self::SUCCESS;
        }

        $expiredIds = [];

        try {
            // Single atomic transaction: any failure rolls back ALL status flips.
            DB::transaction(function () use ($flyers, &$expiredIds): void {
                foreach ($flyers as $flyer) {
                    $flyer->status = FlyerStatus::Expired;
                    // Model save (not quiet): FlyerObserver fires per flyer (purge + sitemap forget).
                    $flyer->save();
                    $expiredIds[] = (int) $flyer->id;
                }
            });
        } catch (Throwable $e) {
            Log::error('ExpireOldFlyersCommand: transaction rolled back, no flyer expired.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('فشلت الأرشفة وتم التراجع عن كل التغييرات: ' . $e->getMessage());

            return self::FAILURE;
        }

        $affected = count($expiredIds);

        // Collect purge URLs for the expired flyers (re-read inside fresh state).
        $allUrls = [];
        $expired = Flyer::with('retailer')->whereIn('id', $expiredIds)->get();
        foreach ($expired as $flyer) {
            try {
                $allUrls[] = route('flyers.show', $flyer->slug);
            } catch (Throwable $e) {
                $allUrls[] = rtrim((string) config('app.url'), '/') . '/offers/' . $flyer->slug;
            }

            if ($flyer->retailer) {
                try {
                    $allUrls[] = route('retailers.show', $flyer->retailer->slug);
                } catch (Throwable $e) {
                    Log::warning('ExpireOldFlyersCommand: retailer URL failed.', ['flyer_id' => $flyer->id]);
                }
            }
        }

        // Ensure homepage and sitemap are purged even if observers handled per-flyer
        $allUrls[] = url('/');
        $allUrls[] = rtrim((string) url('/'), '/') . '/';
        $allUrls[] = url('/sitemap.xml');
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && $appUrl !== url('/')) {
            $allUrls[] = $appUrl;
            $allUrls[] = $appUrl . '/';
            $allUrls[] = $appUrl . '/sitemap.xml';
        }

        $allUrls = array_values(array_unique(array_filter(array_map(static fn (mixed $u): string => trim((string) $u), $allUrls))));

        Cache::forget('sitemap_xml_content');

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
