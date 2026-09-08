<?php

declare(strict_types=1);

use App\Http\Controllers\FlyerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RetailerController;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/offers/{slug}', [FlyerController::class, 'show'])->name('flyers.show');

// Redirect old URLs for SEO preservation
Route::get('/flyer/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));
Route::get('/flyers/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));

// خريطة الموقع لمحركات البحث
Route::get('/sitemap.xml', function () {
    $retailers = Retailer::where('is_active', true)->get();
    $flyers = Flyer::where('status', 'published')
        ->latest('updated_at')
        ->limit(1000)
        ->get();

    return Response::view('sitemap', [
        'retailers' => $retailers,
        'flyers' => $flyers,
    ])->header('Content-Type', 'text/xml; charset=utf-8');
})->name('sitemap');

// Retailer Hub — SEO-optimized dedicated pages /{retailer:slug}
// MUST be last to avoid conflict with /offers/{slug}, /admin, /sitemap.xml
Route::get('/{retailer:slug}', [RetailerController::class, 'show'])
    ->where('retailer', '^(?!offers$|flyer$|flyers$|admin$|api$|storage$|sitemap\.xml$).*')
    ->name('retailers.show');
