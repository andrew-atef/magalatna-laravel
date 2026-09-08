<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FlyerItem;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlyerItemObserver
{
    /**
     * Handle the FlyerItem "creating" event.
     */
    public function creating(FlyerItem $item): void
    {
        $this->populateNormalizedName($item);
    }

    /**
     * Handle the FlyerItem "updating" event.
     */
    public function updating(FlyerItem $item): void
    {
        if ($item->isDirty('product_name')) {
            $this->populateNormalizedName($item);
        }
    }

    public function saved(FlyerItem $item): void
    {
        $this->touchParent($item, 'saved');
    }

    public function updated(FlyerItem $item): void
    {
        // saved already covers updated, keep for spec compliance without double touch
    }

    public function deleted(FlyerItem $item): void
    {
        $this->touchParent($item, 'deleted');
    }

    private function touchParent(FlyerItem $item, string $event): void
    {
        try {
            // Touch the parent flyer so updated_at changes and FlyerObserver is fired
            $flyer = $item->flyer()->first();
            if ($flyer !== null) {
                $flyer->touch();
                Log::info('FlyerItemObserver: Touched parent flyer for cache purge.', [
                    'flyer_item_id' => $item->id,
                    'flyer_id' => $flyer->id,
                    'event' => $event,
                ]);
            } else {
                // Fallback via flyer_id if relation not loaded
                $flyerId = $item->flyer_id;
                if ($flyerId !== null) {
                    try {
                        \App\Models\Flyer::where('id', $flyerId)->touch();
                    } catch (Throwable $e) {
                        Log::warning('FlyerItemObserver: touch via flyer_id failed.', ['flyer_id' => $flyerId, 'error' => $e->getMessage()]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('FlyerItemObserver: touchParent failed.', [
                'flyer_item_id' => $item->id ?? 'unknown',
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function populateNormalizedName(FlyerItem $item): void
    {
        try {
            $item->normalized_name = ArabicNormalizer::normalize((string) $item->product_name);
        } catch (Throwable $e) {
            Log::error('Failed to normalize FlyerItem product_name', [
                'flyer_item_id' => $item->id ?? 'new',
                'product_name' => $item->product_name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to raw product_name if normalization fails
            $item->normalized_name = (string) $item->product_name;
        }
    }
}
