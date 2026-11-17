<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Dynamic 301 recovery for deleted/merged/unpublished legacy flyer slugs —
 * ZERO hardcoded URLs: the slug's retailer prefix + encoded date range are
 * parsed on every 404 to resolve a surviving flyer or the canonical hub.
 */
final class FlyerSlugRecoveryTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Canonical host contract — recovery URLs must NEVER leak the test
        // request host into the Location header.
        config(['app.url' => 'https://magalatna.com']);
    }

    /**
     * Recovering the GSC-documented gomla slug: no flyer row survives, the
     * active retailer does → 301 to its canonical hub.
     */
    public function test_deleted_gomlamarket_flyer_301s_to_retailer_hub(): void
    {
        $this->createRetailerOnly('gomlamarket', 'جملا ماركت');

        $response = $this->get('/offers/gomlamarket-17-26-september-2026');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/gomlamarket');
        // Redirect targets are data-driven but short-cached so a better
        // survivor appearing later is re-pointed quickly (Symfony header
        // bag normalizes the directive order).
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    /**
     * "carrefour" is the CLEAN prefix of retailer slug "carrefouregypt"
     * (FlyerSlugService strips the egypt/misr marketing suffix) — the hub
     * redirect must target the FULL canonical slug, not a fabricated one.
     */
    public function test_deleted_carrefour_flyer_resolves_egypt_suffix_to_canonical_hub(): void
    {
        $this->createRetailerOnly('carrefouregypt', 'كارفور مصر');

        $response = $this->get('/offers/carrefour-17-20-september-2026');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/carrefouregypt');
    }

    /**
     * Dedup kept the canonical base slug and merged the "-part-2" twin away:
     * the legacy part slug must 301 to the surviving flyer itself.
     */
    public function test_part_suffix_301s_to_surviving_canonical_base_flyer(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon'], [
            'slug' => 'kazyon-17-23-november-2026',
            'valid_from' => '2026-11-17',
            'valid_until' => '2026-11-23',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $response = $this->get('/offers/kazyon-17-23-november-2026-part-2');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/offers/kazyon-17-23-november-2026');
    }

    /**
     * Inverse merge case: the ORIGINAL row was deleted and only the
     * "-part-2" twin survived — the date range encoded in the requested slug
     * resolves the survivor even though no slug prefix-match exists.
     */
    public function test_original_slug_301s_to_surviving_part_twin_via_date_match(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon'], [
            'slug' => 'kazyon-17-23-november-2026-part-2',
            'valid_from' => '2026-11-17',
            'valid_until' => '2026-11-23',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $response = $this->get('/offers/kazyon-17-23-november-2026');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/offers/kazyon-17-23-november-2026-part-2');
    }

    /**
     * Cross-month slugs (carrefour-28-february-5-march-2026 shape) must parse
     * into an exact Feb→Mar window when date-matching the surviving "-part-2"
     * twin (the requested original slug prefix-matches no flyer by slug).
     */
    public function test_cross_month_date_range_matches_surviving_flyer(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon'], [
            'slug' => 'kazyon-28-february-5-march-2026-part-2',
            'valid_from' => '2026-02-28',
            'valid_until' => '2026-03-05',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $response = $this->get('/offers/kazyon-28-february-5-march-2026');

        $response->assertStatus(301);
        $response->assertRedirect('https://magalatna.com/offers/kazyon-28-february-5-march-2026-part-2');
    }

    /**
     * Single-day slugs parse into an exact one-day window: with no surviving
     * flyer of that window, traffic still recovers to the hub (replaces the
     * removed hardcoded /offers/kazyon-9-september-2026 Route::redirect).
     */
    public function test_single_day_slug_with_no_survivor_recovers_to_hub(): void
    {
        $this->createRetailerOnly('kazyon', 'كازيون');

        $this->get('/offers/kazyon-9-september-2026')
            ->assertStatus(301)
            ->assertRedirect('https://magalatna.com/kazyon');
    }

    /**
     * A Draft row is never a recovery target: the part slug must NOT render
     * draft content (200) and must NOT 301 onto the draft's own URL — it
     * recovers to the hub instead.
     */
    public function test_draft_flyer_never_leaks_content_and_recovers_to_hub(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon'], [
            'slug' => 'kazyon-17-23-november-2026-part-3',
            'status' => FlyerStatus::Draft,
            'valid_from' => '2026-11-17',
            'valid_until' => '2026-11-23',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $response = $this->get('/offers/kazyon-17-23-november-2026-part-3');

        $response->assertStatus(301);
        $this->assertNotSame(
            'https://magalatna.com/offers/kazyon-17-23-november-2026-part-3',
            (string) ($response->headers->get('Location') ?? ''),
        );
        $response->assertRedirect('https://magalatna.com/kazyon');

        // The draft's own slug must also 404 — never leak 200 OK draft HTML.
        $this->get('/offers/kazyon-17-23-november-2026-part-3')->assertRedirect('https://magalatna.com/kazyon');
    }

    /**
     * Deactivated retailer: no recovery at all — a 301 to a dead hub (404)
     * would be worse than a clean 404.
     */
    public function test_inactive_retailer_slugs_404_instead_of_redirecting_to_dead_hub(): void
    {
        $retailer = $this->createRetailerOnly('gomlamarket', 'جملا ماركت');
        $retailer->update(['is_active' => false]);

        $this->get('/offers/gomlamarket-17-26-september-2026')->assertNotFound();
    }

    /**
     * Garbage slugs that match NO active retailer prefix must keep 404ing —
     * the resolver returns null so junk never earns a redirect.
     */
    public function test_unknown_garbage_slug_returns_clean_404(): void
    {
        // Create ALL retailers BEFORE the first request — the prefix map is
        // cached 1h on first resolution.
        $this->createRetailerOnly('kazyon', 'كازيون');
        $this->createRetailerOnly('bimmisr', 'بيم مصر');

        $this->get('/offers/unknown-garbage-123')->assertNotFound();
        $this->get('/offers/foo-bar-baz')->assertNotFound();
        // Near-miss: "bimmisr" followed by a word is not a flyer slug shape
        // (flyer slugs are always "{prefix}-{digit}...").
        $this->get('/offers/bimmisr-something-17-september-2026')->assertNotFound();
    }

    /**
     * Expired survivors are valid recovery targets (they render 200 with
     * noindex) — traffic keeps flowing to real historical content.
     */
    public function test_expired_surviving_flyer_is_a_valid_recovery_target(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon'], [
            'slug' => 'kazyon-1-10-october-2026',
            'status' => FlyerStatus::Expired,
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-10-10',
        ]);
        $this->addPage($flyer, 'flyers/pages/p1.webp');

        $this->get('/offers/kazyon-1-10-october-2026-part-2')
            ->assertStatus(301)
            ->assertRedirect('https://magalatna.com/offers/kazyon-1-10-october-2026');
    }

    /**
     * The retailer prefix cache must be invalidated by the observer together
     * with the other SEO artifacts, or a newly-deactivated retailer keeps
     * earning redirects for a full hour.
     */
    public function test_retailer_observer_invalidates_recovery_prefix_cache(): void
    {
        Cache::put('flyer_recovery:retailer_prefixes', [
            ['id' => 999, 'prefix' => 'stale-retailer'],
        ], 3600);

        Retailer::query()->create([
            'name' => 'متجر تجريبي',
            'slug' => 'store-'.uniqid(),
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ]);

        // Observers invalidate inside DB::afterCommit() — which only fires at
        // transaction level 0; RefreshDatabase holds one open transaction for
        // the whole test, so commit and re-begin to drain the callbacks.
        $connection = $this->app['db']->connection();
        $connection->commit();
        $connection->beginTransaction();

        $this->assertNull(Cache::get('flyer_recovery:retailer_prefixes'));
    }

    /**
     * Create an active retailer with a FIXED slug and no flyers.
     */
    private function createRetailerOnly(string $slug, string $name): Retailer
    {
        /** @var Retailer $retailer */
        $retailer = Retailer::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'auto_ingest_enabled' => true,
        ]);

        return $retailer;
    }
}
