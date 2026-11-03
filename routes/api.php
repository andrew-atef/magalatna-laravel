<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\RawFacebookIngestController;
use App\Http\Middleware\InternalApiKeyMiddleware;
use App\Http\Middleware\VerifyIngestWebhookSecret;
use Illuminate\Support\Facades\Route;

// Unified ingestion prefix: every POST is authenticated AND throttled (60 RPM).
Route::prefix('v1')->group(function (): void {
    // Exact endpoint targeted by Cloudflare Worker radar: POST /api/v1/facebook/ingest
    Route::post('/facebook/ingest', RawFacebookIngestController::class)
        ->middleware(['ingest.secret', 'throttle:60,1'])
        ->name('api.v1.facebook.ingest');

    Route::prefix('ingest')->group(function (): void {
        // Canonical ingest route
        Route::post('/facebook-raw', RawFacebookIngestController::class)
            ->middleware(['ingest.secret', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook-raw');

        // Short alias
        Route::post('/facebook', RawFacebookIngestController::class)
            ->middleware(['ingest.secret', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook');

        // Optional Sanctum-protected alias (if Sanctum is configured)
        Route::post('/facebook-raw/sanctum', RawFacebookIngestController::class)
            ->middleware(['auth:sanctum', 'throttle:60,1'])
            ->name('api.v1.ingest.facebook-raw.sanctum');
    });

    // Deprecated legacy alias: POST /api/v1/internal/ingest-raw-facebook
    Route::post('/internal/ingest-raw-facebook', RawFacebookIngestController::class)
        ->middleware(['ingest.secret', 'throttle:60,1'])
        ->name('api.v1.internal.ingest-raw-facebook');
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->utc()->toIso8601String()]);
})->name('api.health');
