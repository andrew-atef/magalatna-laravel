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
