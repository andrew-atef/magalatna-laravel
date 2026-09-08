<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Observers\FlyerItemObserver;
use App\Observers\FlyerObserver;
use App\Observers\RetailerObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Flyer::observe(FlyerObserver::class);
        FlyerItem::observe(FlyerItemObserver::class);
        Retailer::observe(RetailerObserver::class);
    }
}
