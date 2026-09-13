<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Support\ArabicNormalizer;
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
        if ($item->isDirty('product_name')) {
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
     * Touch parent flyer updated_at using a direct, silent SQL query.
     * Prevents triggering FlyerObserver::saved and eliminates CDN cache purge storms.
     */
    private function touchParentDirectly(FlyerItem $item): void
    {
        $flyerId = $item->flyer_id;
        if ($flyerId === null) {
            return;
        }

        try {
            // Direct query builder update bypasses Eloquent events to stop Purge Storms
            DB::table('flyers')->where('id', $flyerId)->update(['updated_at' => now()]);
        } catch (Throwable) {
            // Silent error suppression on observer touch
        }
    }

    private function populateNormalizedName(FlyerItem $item): void
    {
        $name = (string) $item->product_name;
        $item->normalized_name = ArabicNormalizer::normalize($name) ?: $name;
    }
}
