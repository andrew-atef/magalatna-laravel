<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Observers\FlyerItemObserver;
use App\Observers\FlyerObserver;
use App\Observers\RetailerObserver;
use Illuminate\Support\Facades\DB;
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

        // Defense-in-depth SQLite concurrency guard (queue workers share one
        // SQLite file): connector config already applies these per connection,
        // re-assert here so artisan/tinker/octane boots are covered too.
        if (DB::connection() instanceof \Illuminate\Database\SQLiteConnection) {
            DB::statement('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA busy_timeout=5000;');
            DB::statement('PRAGMA synchronous=NORMAL;');
        }
    }
}
