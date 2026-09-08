<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'internal_api' => [
        'key' => env('INTERNAL_API_KEY'),
    ],

    // flat key for spec compatibility: config('services.internal_api_key')
    'internal_api_key' => env('INTERNAL_API_KEY'),

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_CACHE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
    ],

    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
        'base_uri' => env('INDEXNOW_BASE_URI', 'https://api.indexnow.org/indexnow'),
    ],

    'indexnow_key' => env('INDEXNOW_KEY'),

    'google_indexing' => [
        'enabled' => env('GOOGLE_INDEXING_ENABLED', false),
        'credentials_path' => storage_path((string) env('GOOGLE_INDEXING_CREDENTIALS_FILE', 'app/google-service-account.json')),
    ],

];
