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
                // Main retailers only (parent_id IS NULL) — child branches are
                // not first-class navigation entries; matches HomeController's
                // active main-retailers query so header links never 404 after
                // a retailer merge sets a parent_id.
                //
                // NO limit(): the old ->limit(12) alphabetically truncated
                // هايبر وان (hyperone) OUT of #mobile-drawer — the drawer is
                // the only navigation surface for every store, so the list
                // must be complete.
                static fn () => Retailer::where('is_active', true)
                    ->whereNull('parent_id')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'logo_path'])
            );

            $view->with('headerRetailers', $headerRetailers);
        });
    }
}
