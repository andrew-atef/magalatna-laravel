<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The observers invalidate the auto-linker caches inside DB::afterCommit() —
 * which only fires when the transaction stack unwinds to level 0.
 * RefreshDatabase keeps ONE open transaction for the whole test, so a helper
 * commits it (firing the callbacks) and re-begins it before asserting.
 */
final class AutoLinkerCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function drainAfterCommitCallbacks(): void
    {
        $connection = $this->app['db']->connection();

        $connection->commit();
        $connection->beginTransaction();
    }

    public function test_saving_retailer_or_brand_forgots_auto_linker_caches(): void
    {
        Cache::put('auto_linker:retailers', ['stale-retailer-map'], 60);
        Cache::put('auto_linker:brands', ['stale-brand-map'], 60);

        Retailer::query()->create([
            'name' => 'متجر تجريبي',
            'slug' => 'store-'.uniqid(),
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ]);

        Brand::query()->create([
            'name' => 'براند تجريبي',
            'slug' => 'brand-'.uniqid(),
        ]);

        $this->drainAfterCommitCallbacks();

        $this->assertNull(Cache::get('auto_linker:retailers'));
        $this->assertNull(Cache::get('auto_linker:brands'));
    }

    public function test_deleting_brand_forgots_auto_linker_brands_cache(): void
    {
        Cache::put('auto_linker:brands', ['stale-brand-map'], 60);

        $brand = Brand::query()->create([
            'name' => 'براند للحذف',
            'slug' => 'brand-delete-'.uniqid(),
        ]);

        $brand->delete();

        $this->drainAfterCommitCallbacks();

        $this->assertNull(Cache::get('auto_linker:brands'));
    }
}
