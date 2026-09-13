<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Retailer;
use Illuminate\Database\Seeder;

class ProductionRetailersSeeder extends Seeder
{
    /**
     * Authoritative Egyptian retail chains with verified official websites
     * and Facebook page URLs. updateOrCreate by slug preserves existing
     * relational foreign keys (retailer_id) — never duplicates slugs.
     */
    public function run(): void
    {
        $retailers = [
            [
                'slug' => 'kazyon',
                'name' => 'كازيون (Kazyon)',
                'website_url' => 'https://kazyon.com',
                'facebook_page_url' => 'https://www.facebook.com/kazyonegypt',
            ],
            [
                'slug' => 'bimmisr',
                'name' => 'بيم (BIM Egypt)',
                'website_url' => 'https://www.bim.eg',
                'facebook_page_url' => 'https://www.facebook.com/BIMmisr',
            ],
            [
                'slug' => 'carrefouregypt',
                'name' => 'كارفور (Carrefour Egypt)',
                'website_url' => 'https://www.carrefouregypt.com',
                'facebook_page_url' => 'https://www.facebook.com/carrefouregypt',
            ],
            [
                'slug' => 'fathalla',
                'name' => 'فتح الله ماركت (Fathalla)',
                'website_url' => 'https://fathallamarket.com',
                'facebook_page_url' => 'https://www.facebook.com/Fathallamarket1948',
            ],
            [
                'slug' => 'gomlamarket',
                'name' => 'جملة ماركت (Gomla Market)',
                'website_url' => 'https://gomlamarket.com',
                'facebook_page_url' => 'https://www.facebook.com/Gomlamarket.Retailcompany',
            ],
            [
                'slug' => 'elfergany',
                'name' => 'أسواق الفرجاني (El Fergany)',
                'website_url' => 'https://elferganymarket.com',
                'facebook_page_url' => 'https://www.facebook.com/marketelfergany',
            ],
            [
                'slug' => 'awladragab',
                'name' => 'أولاد رجب (Awlad Ragab)',
                'website_url' => 'https://awladragab.com',
                'facebook_page_url' => 'https://www.facebook.com/AwladRagabCO',
            ],
            [
                'slug' => 'hyperone',
                'name' => 'هايبر وان (Hyper One)',
                'website_url' => 'https://www.hyperone.com.eg',
                'facebook_page_url' => 'https://www.facebook.com/HyperOneEgypt',
            ],
            [
                'slug' => 'elmontag',
                'name' => 'المنتج لايف (El Montag Live)',
                'website_url' => 'https://www.facebook.com/el.montag.live',
                'facebook_page_url' => 'https://www.facebook.com/el.montag.live',
            ],
        ];

        foreach ($retailers as $data) {
            Retailer::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'website_url' => $data['website_url'],
                    'facebook_page_url' => $data['facebook_page_url'],
                    'currency' => 'EGP',
                    'is_active' => true,
                    'auto_ingest_enabled' => true,
                ]
            );
        }
    }
}
