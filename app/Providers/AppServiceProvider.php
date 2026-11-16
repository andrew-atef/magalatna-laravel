<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Models\Retailer;
use App\Observers\FlyerItemObserver;
use App\Observers\FlyerObserver;
use App\Observers\FlyerPageObserver;
use App\Observers\RetailerObserver;
use Illuminate\Database\SQLiteConnection;
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
        FlyerPage::observe(FlyerPageObserver::class);
        Retailer::observe(RetailerObserver::class);

        // Defense-in-depth SQLite concurrency guard (queue workers share one
        // SQLite file): connector config already applies these per connection,
        // re-assert here so artisan/tinker/octane boots are covered too.
        // journal_mode=WAL is deliberately NOT re-issued here: it is persistent
        // in the database file (set once via config/database.php journal_mode)
        // and re-issuing it on every boot forces an exclusive write lock on
        // every worker/artisan start — on an 840MB box with 3 workers that is
        // pure lock contention.
        if (DB::connection() instanceof SQLiteConnection) {
            DB::statement('PRAGMA busy_timeout=5000;');
            DB::statement('PRAGMA synchronous=NORMAL;');
            // Enforce referential integrity at the engine level (config
            // foreign_key_constraints covers new connections; this covers
            // any boot path that bypasses the connector).
            DB::statement('PRAGMA foreign_keys=ON;');
        }
    }
}
