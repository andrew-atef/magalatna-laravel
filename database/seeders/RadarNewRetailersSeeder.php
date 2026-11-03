<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Retailer;
use Illuminate\Database\Seeder;

/**
 * Seeds the four new radar-target Egyptian retailers.
 *
 * Safe to run multiple times — uses updateOrCreate keyed on slug so it never
 * duplicates rows or clobbers existing relational foreign keys.
 *
 * Run:
 *   php artisan db:seed --class=RadarNewRetailersSeeder
 */
class RadarNewRetailersSeeder extends Seeder
{
    public function run(): void
    {
        $retailers = [
            [
                'slug'              => 'kheirzaman',
                'name'              => 'خير زمان (Kheir Zaman)',
                'website_url'       => 'https://www.khairzaman.com',
                'facebook_page_url' => 'https://www.facebook.com/KheirZaman',
                'logo_path'         => null,
            ],
            [
                'slug'              => 'seoudi',
                'name'              => 'سعودي ماركت (Seoudi Supermarket)',
                'website_url'       => 'https://seoudimarket.com',
                'facebook_page_url' => 'https://www.facebook.com/SeoudiMarket',
                'logo_path'         => null,
            ],
            [
                'slug'              => 'othaim',
                'name'              => 'أسواق العثيم (Othaim Markets Egypt)',
                'website_url'       => 'https://www.othaimmarkets.com',
                'facebook_page_url' => 'https://www.facebook.com/OthaimMarketsEgypt',
                'logo_path'         => null,
            ],
            [
                'slug'              => 'metromarket',
                'name'              => 'مترو ماركت (Metro Market)',
                'website_url'       => 'https://www.metromarket.com.eg',
                'facebook_page_url' => 'https://www.facebook.com/metromarketegypt',
                'logo_path'         => null,
            ],
        ];

        foreach ($retailers as $data) {
            Retailer::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name'              => $data['name'],
                    'website_url'       => $data['website_url'],
                    'facebook_page_url' => $data['facebook_page_url'],
                    'logo_path'         => $data['logo_path'],
                    'currency'          => 'EGP',
                    'is_active'         => true,
                    'auto_ingest_enabled' => true,
                ]
            );
        }

        $this->command->info('RadarNewRetailersSeeder: 4 retailers upserted (kheirzaman, seoudi, othaim, metromarket).');
    }
}
