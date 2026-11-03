<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\RawFacebookIngestController;
use App\Http\Middleware\InternalApiKeyMiddleware;
use App\Http\Middleware\VerifyIngestWebhookSecret;
use Illuminate\Support\Facades\Route;

// Unified ingestion prefix: every POST is authenticated AND throttled (60 RPM).
// No un-throttled POST route may exist in this file.
Route::prefix('v1/ingest')->group(function (): void {
    // Canonical radar webhook: dual-auth (INGEST_WEBHOOK_SECRET + INTERNAL_API_KEY) + throttle.
    // The radar (Cloudflare Worker / GitHub Actions) MUST send:
    //   Authorization: Bearer <INTERNAL_API_KEY>   — or —   X-Internal-Key: <INTERNAL_API_KEY>
    //   AND
    //   X-Ingest-Secret: <INGEST_WEBHOOK_SECRET>   — or —   ?secret=<INGEST_WEBHOOK_SECRET>
    Route::post('/facebook-raw', RawFacebookIngestController::class)
        ->middleware(['ingest.secret', 'internal.api', 'throttle:60,1'])
        ->name('api.v1.ingest.facebook-raw');

    // Optional Sanctum-protected alias (if Sanctum is configured)
    Route::post('/facebook-raw/sanctum', RawFacebookIngestController::class)
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->name('api.v1.ingest.facebook-raw.sanctum');
});

// Deprecated legacy alias: POST /v1/internal/ingest-raw-facebook
// Kept for already-deployed posters; same auth + throttle as canonical.
Route::post('v1/internal/ingest-raw-facebook', RawFacebookIngestController::class)
    ->middleware([InternalApiKeyMiddleware::class, 'throttle:60,1'])
    ->name('api.v1.internal.ingest-raw-facebook');

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->utc()->toIso8601String()]);
})->name('api.health');
