<?php

/*
|--------------------------------------------------------------------------
| Gemini ordered model waterfall (GEMINI_MODELS)
|--------------------------------------------------------------------------
| Comma-separated "name:rpm:rpd" entries in STRICT priority order, e.g.
|   gemini-4-hydrogen:5:20,gemini-3.8-flash:5:20,gemini-3.5-flash-lite:15:500
| Omitted quotas default to 5 RPM / 20 RPD. Empty/unset falls back to the
| single GEMINI_MODEL at 15 RPM / 500 RPD.
|
| Parsed with plain array operations ONLY — no Closures — so the result
| serializes cleanly under `php artisan config:cache`.
*/
$geminiModels = [];
$geminiModelsRaw = trim((string) env('GEMINI_MODELS', ''));

if ($geminiModelsRaw !== '') {
    $geminiSeen = [];

    foreach (explode(',', $geminiModelsRaw) as $geminiEntry) {
        $geminiEntry = trim($geminiEntry);

        if ($geminiEntry === '') {
            continue;
        }

        $geminiParts = explode(':', $geminiEntry, 3);
        $geminiName = trim($geminiParts[0]);

        // Skip blanks and duplicate names (a repeated model would double-spend
        // its own RPM/RPD budget inside one waterfall pass).
        if ($geminiName === '' || isset($geminiSeen[$geminiName])) {
            continue;
        }

        $geminiSeen[$geminiName] = true;
        $geminiModels[] = [
            'name' => $geminiName,
            'rpm' => isset($geminiParts[1]) && trim($geminiParts[1]) !== '' ? max(0, (int) trim($geminiParts[1])) : 5,
            'rpd' => isset($geminiParts[2]) && trim($geminiParts[2]) !== '' ? max(0, (int) trim($geminiParts[2])) : 20,
        ];
    }
}

if ($geminiModels === []) {
    $geminiFallbackName = trim((string) env('GEMINI_MODEL', 'gemini-2.5-flash'));

    if ($geminiFallbackName === '') {
        $geminiFallbackName = 'gemini-2.5-flash';
    }

    $geminiModels = [
        ['name' => $geminiFallbackName, 'rpm' => 15, 'rpd' => 500],
    ];
}

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
        // Ordered priority waterfall with per-model RPM/RPD quotas (parsed
        // above). GeminiVisionService iterates this list on EVERY text/vision/
        // OCR/script call; ReelScriptService shares the same engine.
        'models' => $geminiModels,
        // Audio synthesis (TTS) model — speechMetadata + responseModalities:
        // ["audio"] require a TTS-capable model on the streamGenerateContent
        // endpoint. Non-TTS models answer 400 "Speech metadata is not
        // supported for this model".
        'tts_model' => env('GEMINI_TTS_MODEL', 'gemini-3.8-flash-tts'),
    ],

    // Vertical Reel/Short generation (Gemini TTS + low-memory ffmpeg + R2).
    'reels' => [
        'auto_generate' => env('REELS_AUTO_GENERATE', true),
        'voice_name' => env('REELS_VOICE_NAME', 'Tavi'),
        'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe_bin' => env('FFPROBE_BIN', 'ffprobe'),
        'temp_dir' => env('REELS_TEMP_DIR', sys_get_temp_dir()),
        // Burned-in captions: the font FAMILY libass matches through the
        // system font provider (fontconfig on the VPS — install an Arabic
        // font with `apt install fonts-noto-core`, then set the name here).
        // NEVER a Latin-only family like DejaVu Sans: libass would fall back
        // per-glyph and the Arabic would render with broken shaping.
        'caption_font_name' => env('REELS_CAPTION_FONT_NAME', 'Noto Sans Arabic'),
        'caption_font_size' => env('REELS_CAPTION_FONT_SIZE', 54),
        // Closing CTA card shown big & centered during the silent tail.
        'endcard_font_size' => env('REELS_ENDCARD_FONT_SIZE', 62),
    ],

    'facebook_proxy' => [
        'url' => env('FACEBOOK_PROXY_URL', ''),
        'secret' => env('FACEBOOK_PROXY_SECRET', ''),
    ],

    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY', ''),
    ],

    'cloudflare_harvester' => [
        'url' => env('CLOUDFLARE_HARVESTER_URL', ''),
        'secret' => env('INTERNAL_API_KEY', ''),
    ],

    'internal_api' => [
        'key' => env('INTERNAL_API_KEY'),
    ],

    // flat key for spec compatibility: config('services.internal_api_key')
    'internal_api_key' => env('INTERNAL_API_KEY'),

    // Radar webhook secret — shared between the Cloudflare Worker/GitHub Actions
    // radar and this Laravel app. Used by VerifyIngestWebhookSecret middleware.
    'ingest_webhook' => [
        'secret' => env('INGEST_WEBHOOK_SECRET'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_CACHE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
    ],

    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
        'base_uri' => env('INDEXNOW_BASE_URI', 'https://api.indexnow.org/indexnow'),
    ],

    'indexnow_key' => env('INDEXNOW_KEY'),

    'google' => [
        'analytics_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    'google_indexing' => [
        'enabled' => env('GOOGLE_INDEXING_ENABLED', false),
        'credentials_path' => storage_path((string) env('GOOGLE_INDEXING_CREDENTIALS_FILE', 'app/google-service-account.json')),
    ],

];
