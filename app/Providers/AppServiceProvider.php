<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\FlyerItem;
use App\Observers\FlyerItemObserver;
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
        FlyerItem::observe(FlyerItemObserver::class);
    }
}
