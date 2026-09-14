<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Retailer;
use Illuminate\Database\Seeder;

class ProductionRetailersSeeder extends Seeder
{
    /**
     * Authoritative Egyptian retail chains: verified official websites,
     * validated Facebook page URLs and R2 logo keys (all HTTP-verified).
     * updateOrCreate by slug preserves existing relational foreign keys
     * (retailer_id) — never duplicates slugs.
     */
    public function run(): void
    {
        $retailers = [
            [
                'slug' => 'kazyon',
                'name' => 'كازيون (Kazyon)',
                'website_url' => 'https://kazyon.com',
                'facebook_page_url' => 'https://www.facebook.com/kazyonegypt',
                'logo_path' => 'retailer-logos/01M20P36HS3N5PS3VCG4YAHNHW.webp',
            ],
            [
                'slug' => 'bimmisr',
                'name' => 'بيم (BIM Egypt)',
                'website_url' => 'https://www.bim.eg',
                'facebook_page_url' => 'https://www.facebook.com/BIMmisr',
                'logo_path' => 'retailer-logos/01M20P6K52S6RAHZX1SYD5THE7.webp',
            ],
            [
                'slug' => 'carrefouregypt',
                'name' => 'كارفور (Carrefour Egypt)',
                'website_url' => 'https://www.carrefouregypt.com',
                'facebook_page_url' => 'https://www.facebook.com/carrefouregypt',
                'logo_path' => 'retailer-logos/01M20P9WWGV4XAW57AG540557A.webp',
            ],
            [
                'slug' => 'fathalla',
                'name' => 'فتح الله ماركت (Fathalla)',
                'website_url' => 'https://fathallamarket.com.eg',
                'facebook_page_url' => 'https://www.facebook.com/Fathallamarket1948',
                'logo_path' => 'retailer-logos/01M21DN0WJG51FR4GPRZZ6MRG1.webp',
            ],
            [
                'slug' => 'gomlamarket',
                'name' => 'جملة ماركت (Gomla Market)',
                'website_url' => 'https://gomlamarket.com',
                'facebook_page_url' => 'https://www.facebook.com/Gomlamarket.Retailcompany',
                'logo_path' => 'retailer-logos/01M21DQCD6KN60AZSADKAMDV7Q.webp',
            ],
            [
                'slug' => 'elfergany',
                'name' => 'أسواق الفرجاني (El Fergany)',
                'website_url' => 'https://elfergany.com',
                'facebook_page_url' => 'https://www.facebook.com/marketelfergany',
                'logo_path' => 'retailer-logos/01M21DTC829B07QPWVE7M3KVB0.webp',
            ],
            [
                'slug' => 'awladragab',
                'name' => 'أولاد رجب (Awlad Ragab)',
                'website_url' => 'https://www.awladragab.com',
                'facebook_page_url' => 'https://www.facebook.com/AwladRagabco',
                'logo_path' => 'retailer-logos/01M21DJC1N7APHAZRFJ3RW3A8A.webp',
            ],
            [
                'slug' => 'hyperone',
                'name' => 'هايبر وان (Hyper One)',
                'website_url' => 'https://www.hyperone.com.eg',
                'facebook_page_url' => 'https://www.facebook.com/HyperOneEgypt',
                'logo_path' => 'retailer-logos/01M21DN0WJG51FR4GPRZZ6MRG1.webp',
            ],
            [
                'slug' => 'elmontag',
                'name' => 'المنتج لايف (El Montag Live)',
                'website_url' => 'https://www.facebook.com/el.montag.live',
                'facebook_page_url' => 'https://www.facebook.com/el.montag.live',
                'logo_path' => null,
            ],
        ];

        foreach ($retailers as $data) {
            Retailer::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'website_url' => $data['website_url'],
                    'facebook_page_url' => $data['facebook_page_url'],
                    'logo_path' => $data['logo_path'],
                    'currency' => 'EGP',
                    'is_active' => true,
                    'auto_ingest_enabled' => true,
                ]
            );
        }
    }
}
