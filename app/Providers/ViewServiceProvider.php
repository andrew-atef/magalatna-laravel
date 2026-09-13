<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Share cached header retailers with the master layout.
     *
     * Only the underlying data collection is cached (24h) — never view
     * instances. Keeps Eloquent queries out of Blade templates.
     */
    public function boot(): void
    {
        View::composer('components.layouts.app', static function ($view): void {
            $headerRetailers = Cache::remember(
                'layout:header_retailers',
                86400,
                static fn () => Retailer::where('is_active', true)
                    ->orderBy('name')
                    ->limit(12)
                    ->get(['id', 'name', 'slug', 'logo_path'])
            );

            $view->with('headerRetailers', $headerRetailers);
        });
    }
}
