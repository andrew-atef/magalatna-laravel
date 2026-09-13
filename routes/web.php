<?php

declare(strict_types=1);

use App\Enums\FlyerStatus;
use App\Http\Controllers\FlyerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\RetailerController;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Static legal/informational pages — MUST be before catch-all retailer route
Route::get('/about-us', [PageController::class, 'about'])->name('pages.about');
Route::get('/privacy-policy', [PageController::class, 'privacy'])->name('pages.privacy');
Route::get('/terms-of-use', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/contact-us', [PageController::class, 'contact'])->name('pages.contact');
Route::get('/offers/{slug}', [FlyerController::class, 'show'])->name('flyers.show');

// Redirect old URLs for SEO preservation
Route::get('/flyer/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));
Route::get('/flyers/{slug}', fn (string $slug) => redirect()->route('flyers.show', $slug, 301));

// Legacy retailer aliases -> canonical slugs (301 to preserve link equity)
Route::permanentRedirect('/bim', '/bimmisr');
Route::permanentRedirect('/carrefour', '/carrefouregypt');

Route::get('/llms.txt', [LlmsTxtController::class, 'index'])->name('llms.txt');

// خريطة الموقع لمحركات البحث — cached 6h, invalidated via observers (FlyerObserver::saved/deleted)
// Memory-guarded: max 300 flyers, column-selected, cover page only (no full pages hydration).
Route::get('/sitemap.xml', function () {
    $xml = Cache::remember('sitemap_xml_content', 21600, function () {
        $retailers = Retailer::where('is_active', true)->get();
        $activeFlyers = Flyer::where('status', FlyerStatus::Published)
            ->select(['id', 'slug', 'retailer_id', 'title', 'updated_at'])
            ->with([
                'retailer' => static fn ($q) => $q->select(['id', 'name', 'slug', 'logo_path']),
                'pages' => static fn ($q) => $q->select(['id', 'flyer_id', 'image_path', 'page_number'])
                    ->where('page_number', 1)
                    ->orderBy('page_number')
                    ->limit(1),
            ])
            ->latest('updated_at')
            ->limit(300)
            ->get();
        $expiredFlyers = Flyer::where('status', FlyerStatus::Expired)
            ->select(['id', 'slug', 'retailer_id', 'updated_at'])
            ->with(['retailer'])
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return view('sitemap', [
            'retailers' => $retailers,
            'activeFlyers' => $activeFlyers,
            'expiredFlyers' => $expiredFlyers,
            // Back-compat for older view variable name
            'flyers' => $activeFlyers,
        ])->render();
    });

    return Response::make($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
})->name('sitemap');

// Retailer Hub — SEO-optimized dedicated pages /{retailer:slug}
// MUST be last to avoid conflict with /offers/{slug}, /admin, /sitemap.xml, /about-us etc.
Route::get('/{retailer:slug}', [RetailerController::class, 'show'])
    ->where('retailer', '^(?!offers$|flyer$|flyers$|admin$|api$|storage$|up$|livewire$|build$|assets$|favicon\.ico$|sitemap\.xml$|llms\.txt$|about-us$|privacy-policy$|terms-of-use$|contact-us$).*')
    ->name('retailers.show');
