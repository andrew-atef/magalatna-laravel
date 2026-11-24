<?php

declare(strict_types=1);

/**
 * Post-OCR correction dictionaries (Phase 1 unified normalization).
 *
 * Gemini Vision OCR misreads Egyptian brand names in two predictable ways:
 * letter-for-digit unit confusion and phonetic brand spelling drift. These
 * maps are the SINGLE source of truth consumed by
 * App\Services\ProductDataNormalizer — edit here, never in code.
 *
 * Key rule: RAW keys are matched against the OCR output exactly as printed
 * (before ArabicNormalizer::normalize — keep hamza/ya/ta-marbuta variants
 * as separate keys when OCR produces both). Values are the CANONICAL forms
 * shown to shoppers and stored on Brand rows.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Product-name corrections
    |--------------------------------------------------------------------------
    | Applied word-boundary-safe to every extracted product name.
    */
    'product_corrections' => [
        'دولتين' => 'دولفين',
        'بيبيسي' => 'بيبسي',
        'بيبسى' => 'بيبسي',
        'إفيف' => 'إلفيف',
        'افيف' => 'إلفيف',
        'إلنيف' => 'إلفيف',
        'النيف' => 'إلفيف',
        'جينتي' => 'جبنتي',
        'خالتي بهيه' => 'خالتي بهية',
        'زينه' => 'زينة',
        'كامى' => 'كامي',
        'شاييني' => 'شايني',
        'شايلي' => 'شايني',
        'شايي' => 'شايني',
        'Shiny' => 'شايني',
        'ليبشون' => 'ليبتون',
        'ماينز' => 'هاينز',
        'سترسس' => 'ستربس',
        'فينا' => 'فيينا',
        'لينيتا' => 'لبنيتا',
        'نسكافية' => 'نسكافيه',
        'جهينه' => 'جهينة',
        'Beef Kofta' => 'كفتة بقري',
        'Köfta' => 'كفتة',
        'köfta' => 'كفتة',
        'Kofta' => 'كفتة',
        'kofta' => 'كفتة',
        'Balady Beef Ribeye' => 'ريب آي بقري بلدي',
        'Balady Beef Escalope' => 'اسكالوب بقري بلدي',
        'Balady Beef Mince' => 'لحم مفروم بقري بلدي',
        'Balady Lamb Shoulder' => 'كتف ضاني بلدي',
        'Sliced Liver' => 'كبدة شرائح',
        'My Choice' => 'ماي تشويس',
        'Balady' => 'بلدي',
    ],

    /*
    |--------------------------------------------------------------------------
    | Brand aliases
    |--------------------------------------------------------------------------
    | RAW brand (as OCR prints it, matched on ArabicNormalizer::normalize()
    | + lowercase form) => canonical Brand name persisted in `brands`.
    | Latin keys are lowercase; Arabic keys are stored UNnormalized and
    | normalized at lookup time.
    */
    'brand_aliases' => [
        // Elvive / OCR drift
        'elvive' => 'إلفيف',
        'لوريال' => 'إلفيف',
        'إفيف' => 'إلفيف',
        'افيف' => 'إلفيف',
        'إلنيف' => 'إلفيف',
        'النيف' => 'إلفيف',
        'اليف' => 'إلفيف',

        // Lipton
        'lipton' => 'ليبتون',
        'لبتون' => 'ليبتون',

        // Palmolive OCR drift
        'دولتين' => 'دولفين',
        'دولفين' => 'دولفين',

        // Pepsi
        'بيبيسي' => 'بيبسي',
        'بيبسى' => 'بيبسي',
        'pepsi' => 'بيبسي',

        // Chewing gum brand جبنتي
        'جينتي' => 'جبنتي',
        'جنتي' => 'جبنتي',
        'جبنتي' => 'جبنتي',

        // Egyptian dairy staples
        'جيهاز' => 'جهينة',
        'جهينه' => 'جهينة',
        'المراعي' => 'المراعي',
        'المراغي' => 'المراعي',

        // OCR drifts
        'شاييني' => 'شايني',
        'شايلي' => 'شايني',
        'شايي' => 'شايني',
        'shiny' => 'شايني',
        'Shiny' => 'شايني',
        'ليبشون' => 'ليبتون',
        'ماينز' => 'هاينز',
        'سترسس' => 'ستربس',
        'فينا' => 'فيينا',
        'لينيتا' => 'لبنيتا',
        'نسكافية' => 'نسكافيه',

        // Cooking oil staples
        'عافيه' => 'عافية',
        'كريستال' => 'كريستال',
        'الممتاز' => 'الممتاز',
        'شطاره' => 'شطارة',

        // Home care
        'برسيل' => 'برسيل',
        'ariel' => 'أريال',
        'اريال' => 'أريال',
        'تايد' => 'تايد',
        'pril' => 'بريل',
        'بريل' => 'بريل',
        'فيري' => 'فيري',
        'بليتشي' => 'بليتشي',
        'بلادك' => 'بلادك',
        'بانتين' => 'بانتين',
        'سيف' => 'سيف',
        'My Choice' => 'ماي تشويس',
        'my choice' => 'ماي تشويس',
        'Balady' => 'بلدي',
        'balady' => 'بلدي',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuzzy matching (dynamic brand snapping)
    |--------------------------------------------------------------------------
    */
    'fuzzy_brand' => [
        // Only brand strings at least this long may snap to an existing Brand
        // (short Arabic names like "رز"/"كس" would false-positive).
        'min_length' => 5,
        // mb-safe Levenshtein distance cap on normalized forms.
        'max_distance' => 1,
    ],
];
