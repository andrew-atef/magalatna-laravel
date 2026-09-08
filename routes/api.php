<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\RawFacebookIngestController;
use App\Http\Middleware\InternalApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Protected via InternalApiKeyMiddleware (Bearer token)
    // Alternative: use 'auth:sanctum' if using Sanctum tokens
    Route::post('/ingest/facebook-raw', RawFacebookIngestController::class)
        ->middleware(['internal.api'])
        ->name('api.v1.ingest.facebook-raw');

    // Optional Sanctum-protected alias (if Sanctum is configured)
    Route::post('/ingest/facebook-raw/sanctum', RawFacebookIngestController::class)
        ->middleware(['auth:sanctum'])
        ->name('api.v1.ingest.facebook-raw.sanctum');
});

// Spec: v1/internal with InternalApiKeyMiddleware class
Route::prefix('v1/internal')
    ->middleware(InternalApiKeyMiddleware::class)
    ->group(function (): void {
        Route::post('/ingest-raw-facebook', RawFacebookIngestController::class)
            ->name('api.v1.internal.ingest-raw-facebook');
    });

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
})->name('api.health');
