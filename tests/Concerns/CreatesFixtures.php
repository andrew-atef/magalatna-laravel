<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Models\Retailer;

trait CreatesFixtures
{
    /**
     * @param  array<string, mixed>  $retailerAttributes
     * @param  array<string, mixed>  $flyerAttributes
     */
    protected function createPublishedFlyer(
        array $retailerAttributes = [],
        array $flyerAttributes = [],
    ): Flyer {
        /** @var Retailer $retailer */
        $retailer = Retailer::query()->create(array_merge([
            'name' => 'كازيون مصر',
            'slug' => 'kazyon-'.uniqid(),
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ], $retailerAttributes));

        /** @var Flyer $flyer */
        $flyer = Flyer::query()->create(array_merge([
            'retailer_id' => $retailer->id,
            'title' => 'مجلة عروض كازيون الأسبوعية',
            'valid_from' => now('Africa/Cairo')->subDay()->toDateString(),
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'status' => FlyerStatus::Published,
            'total_pages' => 1,
        ], $flyerAttributes));

        return $flyer->refresh();
    }

    protected function addPage(Flyer $flyer, string $imagePath, int $pageNumber = 1): FlyerPage
    {
        /** @var FlyerPage $page */
        $page = $flyer->pages()->create([
            'page_number' => $pageNumber,
            'image_path' => $imagePath,
            'width' => 1200,
            'height' => 1600,
        ]);

        return $page;
    }

    protected function addItem(Flyer $flyer, array $attributes = []): FlyerItem
    {
        /** @var FlyerItem $item */
        $item = $flyer->items()->create(array_merge([
            'product_name' => 'زيت عافية 1.5 لتر',
            'slug' => 'oil-'.uniqid(),
            'sale_price' => 90.50,
            'old_price' => 120.00,
            'discount_percent' => 24.58,
        ], $attributes));

        return $item;
    }
}
