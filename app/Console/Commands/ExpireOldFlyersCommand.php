<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireOldFlyersCommand extends Command
{
    protected $signature = 'flyers:expire';

    protected $description = 'تحويل المجلات والعروض المنتهية الصلاحية بتوقيت القاهرة إلى حالة expired';

    public function handle(): int
    {
        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();

        $flyers = Flyer::with('retailer')
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '<', $cairoToday)
            ->get();

        if ($flyers->isEmpty()) {
            $this->info('لا توجد مجلات منتهية للتحويل اليوم.');
            Cache::forget('sitemap_xml_content');

            return self::SUCCESS;
        }

        $affected = 0;
        $allUrls = [];

        foreach ($flyers as $flyer) {
            try {
                $flyer->status = FlyerStatus::Expired;
                $flyer->save(); // Fires FlyerObserver saved -> purge + sitemap forget

                $affected++;

                // Collect URLs for manual batch purge as fallback
                try {
                    $allUrls[] = route('flyers.show', $flyer->slug);
                } catch (Throwable $e) {
                    $allUrls[] = rtrim((string) config('app.url'), '/') . '/offers/' . $flyer->slug;
                }

                try {
                    if ($flyer->retailer) {
                        $allUrls[] = route('retailers.show', $flyer->retailer->slug);
                    }
                } catch (Throwable $e) {
                    Log::warning('ExpireOldFlyersCommand: retailer URL failed.', ['flyer_id' => $flyer->id]);
                }
            } catch (Throwable $e) {
                Log::error('ExpireOldFlyersCommand: Failed to expire flyer.', [
                    'flyer_id' => $flyer->id,
                    'error' => $e->getMessage(),
                ]);
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

        $allUrls = array_values(array_unique(array_filter(array_map(static fn (string $u): string => trim($u), $allUrls))));

        Cache::forget('sitemap_xml_content');

        if ($allUrls !== []) {
            try {
                PurgeCloudflareCacheJob::dispatch($allUrls);
                Log::info('ExpireOldFlyersCommand: Dispatched batch purge for expired flyers.', [
                    'affected' => $affected,
                    'urls_count' => count($allUrls),
                ]);
            } catch (Throwable $e) {
                Log::error('ExpireOldFlyersCommand: Failed to dispatch batch purge.', ['error' => $e->getMessage()]);
            }
        }

        $this->info("تمت أرشفة وتحديث {$affected} مجلة منتهية الصلاحية.");

        return self::SUCCESS;
    }
}
