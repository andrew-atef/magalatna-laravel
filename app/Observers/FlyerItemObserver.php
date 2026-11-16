<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FlyerItemObserver
{
    public function creating(FlyerItem $item): void
    {
        $this->populateNormalizedName($item);
    }

    public function updating(FlyerItem $item): void
    {
        if ($item->isDirty('product_name') || empty($item->normalized_name)) {
            $this->populateNormalizedName($item);
        }
    }

    public function saved(FlyerItem $item): void
    {
        $this->touchParentDirectly($item);
    }

    public function deleted(FlyerItem $item): void
    {
        $this->touchParentDirectly($item);
    }

    /**
     * Refresh the parent flyer's updated_at so listing ordering stays fresh,
     * and purge its canonical CDN URL — WITHOUT firing FlyerObserver events
     * (no recursion, no purge storms).
     *
     *  - Web/Filament edits are rare: always touch + purge.
     *  - CLI/queue bulk OCR inserts (50+ items in seconds) are debounced with a
     *    short cache lock so we acquire ONE SQLite write lock per 10s window
     *    instead of one per item, and emit one purge per window.
     */
    private function touchParentDirectly(FlyerItem $item): void
    {
        $flyerId = $item->flyer_id;
        if ($flyerId === null) {
            return;
        }

        try {
            if (app()->runningInConsole()) {
                // Debounce window: only the first item in 10s does the write.
                if (! Cache::add("flyer_item_touch_lock:{$flyerId}", true, 10)) {
                    return;
                }
            }

            // Direct query-builder update bypasses Eloquent events entirely.
            DB::table('flyers')->where('id', $flyerId)->update(['updated_at' => now()]);

            // CDN purge for the parent flyer URL (canonical host only).
            $flyer = Flyer::query()
                ->whereKey($flyerId)
                ->first(['id', 'slug', 'status']);

            if ($flyer !== null && $flyer->status === FlyerStatus::Published && (string) $flyer->slug !== '') {
                $base = rtrim((string) config('app.url'), '/');
                $url = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                PurgeCloudflareCacheJob::dispatch([$url])->afterCommit();
            }
        } catch (Throwable) {
            // Silent error suppression on observer touch.
        }
    }

    private function populateNormalizedName(FlyerItem $item): void
    {
        $name = (string) $item->product_name;
        $item->normalized_name = ArabicNormalizer::normalize($name) ?: $name;
    }
}
