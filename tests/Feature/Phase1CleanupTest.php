<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Brand;
use App\Models\FlyerItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Phase 1 feature coverage: Richest-Row Winner dedup through the
 * Gatekeeper job, nearest-integer discount rounding in the deterministic
 * copy builders, and the flyers:clean-items retroactive cleanup command.
 */
final class Phase1CleanupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFixtures;

    // ---------------------------------------------------------------------
    // GatekeeperFacebookPostJob::dedupFlyerItems — Richest-Row Winner
    // ---------------------------------------------------------------------

    public function test_dedup_flyer_items_keeps_richest_row_and_merges(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'richest-row-offer']);

        // Cover-page read: same offer, POOR row (no old_price, no unit).
        $poor = $this->addItem($flyer, [
            'product_name' => 'زيت عافية 1.5 لتر',
            'sale_price' => 90.50,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);

        // Inner-page read: the RICHER row.
        $rich = $this->addItem($flyer, [
            'product_name' => 'زيت عافية 1.5 لتر',
            'sale_price' => 90.50,
            'old_price' => 120.00,
            'discount_percent' => 24.58,
            'unit' => 'لتر',
        ]);

        $removed = GatekeeperFacebookPostJob::dedupFlyerItems($flyer->refresh());

        $this->assertSame(1, $removed);
        $this->assertSame(1, $flyer->items()->count());

        $survivor = $flyer->items()->first();
        $this->assertSame($rich->id, $survivor->id);
        $this->assertEquals(120.00, (float) $survivor->old_price);
        $this->assertSame('لتر', (string) $survivor->unit);
        $this->assertEquals(24.58, (float) $survivor->discount_percent);
    }

    public function test_dedup_flyer_items_merges_missing_fields_onto_earlier_survivor(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'merge-onto-survivor-offer']);

        // Earlier row is the RICHEST (old_price + unit → 13 points) — it stays
        // the survivor and ADOPTS the later row's brand_id before it dies.
        $earlier = $this->addItem($flyer, [
            'product_name' => 'زيت عافية 1.5 لتر',
            'sale_price' => 90.50,
            'old_price' => 120.00,
            'discount_percent' => 24.58,
            'unit' => 'لتر',
        ]);

        $brand = Brand::query()->create(['name' => 'عافية', 'slug' => 'aafia-'.uniqid()]);

        $later = $this->addItem($flyer, [
            'product_name' => 'زيت عافية 1.5 لتر',
            'sale_price' => 90.50,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);
        $later->forceFill(['brand_id' => $brand->id])->saveQuietly();

        $removed = GatekeeperFacebookPostJob::dedupFlyerItems($flyer->refresh());

        $this->assertSame(1, $removed);
        $this->assertSame(1, $flyer->items()->count());

        $survivor = $flyer->items()->first();
        $this->assertSame($earlier->id, $survivor->id);
        $this->assertEquals(120.00, (float) $survivor->old_price);
        $this->assertSame($brand->id, $survivor->brand_id);
    }

    public function test_dedup_flyer_items_never_touches_distinct_products(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'distinct-products-offer']);

        $this->addItem($flyer, ['product_name' => 'زيت خليط 5 لتر', 'sale_price' => 113.99]);
        $this->addItem($flyer, ['product_name' => 'أرز مصري 5 كجم', 'sale_price' => 113.99]);

        $this->assertSame(0, GatekeeperFacebookPostJob::dedupFlyerItems($flyer->refresh()));
        $this->assertSame(2, $flyer->items()->count());
    }

    // ---------------------------------------------------------------------
    // Nearest-integer discount rounding (BLUF + editorial)
    // ---------------------------------------------------------------------

    public function test_bluf_and_editorial_round_discount_to_nearest_integer(): void
    {
        // Fast-fail every Gemini call with a non-Arabic response: the Arabic
        // ratio gate rejects it immediately (no retry backoff) and both copy
        // builders fall through to the DETERMINISTIC builders under test.
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'browse offers available now']]]],
                ],
            ]),
        ]);

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'rounding-offer']);
        // 24.58% → must render as 25%, never a fake-precision decimal.
        $this->addItem($flyer, [
            'sale_price' => 90.50,
            'old_price' => 120.00,
            'discount_percent' => 24.58,
        ]);

        $flyer = $flyer->refresh();

        $bluf = GatekeeperFacebookPostJob::buildBlufSummary($flyer);
        $editorial = GatekeeperFacebookPostJob::buildEditorialOverview($flyer);

        $this->assertStringContainsString('بخصومات تصل إلى 25%', $bluf);
        $this->assertStringNotContainsString('24.58', $bluf);

        $this->assertStringContainsString('بخصومات 25%', $editorial);
        $this->assertStringNotContainsString('24.58', $editorial);
    }

    // ---------------------------------------------------------------------
    // flyers:clean-items — retroactive normalization + dedup + copy rebuild
    // ---------------------------------------------------------------------

    public function test_clean_items_command_normalizes_categorizes_dedups_and_rebuilds(): void
    {
        Queue::fake();

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'clean-items-offer']);

        // OCR letter-for-digit unit error → 'زيت خليط 1 كجم'.
        $unitError = $this->addItem($flyer, [
            'product_name' => 'زيت خليط ا كجم',
            'sale_price' => 50.00,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);

        // Cover-vs-inner duplicate (extra whitespace, identical offer).
        $this->addItem($flyer, [
            'product_name' => 'زيت خليط  1 كجم',
            'sale_price' => 50.00,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);

        // Brand spelling drift: legacy Brand row 'دولتين' must resolve to
        // the canonical 'دولفين'.
        $legacyBrand = Brand::query()->create(['name' => 'دولتين', 'slug' => 'doltin-legacy']);
        $drifted = $this->addItem($flyer, [
            'product_name' => 'دولتين صابون',
            'sale_price' => 20.00,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);
        $drifted->forceFill(['brand_id' => $legacyBrand->id])->saveQuietly();

        $this->artisan('flyers:clean-items')->assertSuccessful();

        $flyer = $flyer->refresh();

        // 1. Name normalized + category deterministically assigned.
        $unitError = FlyerItem::query()->findOrFail($unitError->id);
        $this->assertSame('زيت خليط 1 كجم', (string) $unitError->product_name);
        $this->assertSame('oils_ghee', (string) $unitError->category);

        // 2. Duplicate collapsed (Richest-Row Winner).
        $this->assertSame(2, $flyer->items()->count());

        // 3. Brand drift resolved + personal_care category.
        $drifted = FlyerItem::query()->findOrFail($drifted->id);
        $this->assertSame('دولفين صابون', (string) $drifted->product_name);
        $this->assertSame('personal_care', (string) $drifted->category);
        $this->assertNotNull($drifted->brand_id);
        $this->assertNotSame($legacyBrand->id, $drifted->brand_id);
        $this->assertSame('دولفين', (string) $drifted->brand->name);

        // 4. BLUF rebuilt with the DETERMINISTIC fallback (no discounts
        //    survived the dedup → the zero-percent protection phrase).
        $this->assertStringContainsString('بأسعار تنافسية مخفضة', (string) $flyer->bluf_summary);

        // 5. Purge dispatched for the published flyer.
        Queue::assertPushed(PurgeCloudflareCacheJob::class);
    }

    public function test_clean_items_dry_run_never_persists_anything(): void
    {
        Queue::fake();

        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], ['slug' => 'dry-run-offer']);

        $item = $this->addItem($flyer, [
            'product_name' => 'زيت خليط ا كجم',
            'sale_price' => 50.00,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);
        $this->addItem($flyer, [
            'product_name' => 'زيت خليط  1 كجم',
            'sale_price' => 50.00,
            'old_price' => null,
            'savings_amount' => null,
            'discount_percent' => null,
        ]);

        // Purges pushed by item-creation observers during fixture setup are
        // expected; the COMMAND itself must not add any in dry-run.
        $purgesBefore = count(Queue::pushed(PurgeCloudflareCacheJob::class));

        $this->artisan('flyers:clean-items', ['--dry-run' => true])->assertSuccessful();

        $item = FlyerItem::query()->findOrFail($item->id);
        $this->assertSame('زيت خليط ا كجم', (string) $item->product_name);
        $this->assertNull($item->category);
        $this->assertSame(2, $flyer->items()->count());

        // bluf_summary is null in STORAGE — the accessor's fallback generator
        // only synthesizes copy for EMPTY stored values.
        $this->assertNull($flyer->refresh()->getAttributes()['bluf_summary'] ?? null);

        $this->assertCount($purgesBefore, Queue::pushed(PurgeCloudflareCacheJob::class));
    }
}
