<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Jobs\RegenerateFlyerContentJob;
use App\Models\FlyerItem;
use App\Services\GeminiVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Safe regenerate-content action: 'full' re-OCR from stored R2 binaries with
 * zero-data-loss staging, 'copy' text-only refresh. The SEO slug never
 * changes; a failed/empty OCR run never wipes a live flyer's items.
 */
final class RegenerateFlyerContentTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.models' => [
                ['name' => 'gemini-4-hydrogen', 'rpm' => 15, 'rpd' => 500],
            ],
        ]);

        Storage::fake('r2');
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function blufText(): string
    {
        return 'تصفح عروض كازيون الساري في مصر حتى 30 نوفمبر 2026، بخصومات تصل إلى 25%. '
            .'يشمل العرض تخفيضات قوية على الزيت والسكر والأرز بجميع الفروع وحتى نفاذ الكمية.';
    }

    private function editorialText(): string
    {
        return "أبرز نقاط عرض كازيون:\n- زيت عافية 1.5 لتر بسعر 90.5 ج.م (خصم 25%).\n- سكر 1 كجم بسعر 41.95 ج.م.\n- العرض ساري بجميع الفروع حتى نفاذ الكمية.";
    }

    public function test_full_mode_reads_r2_preserves_slug_normalizes_dedups_and_purges(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'kazyon-regen-offer',
            'discover_image_path' => 'flyers/discover-preset.webp',
        ]);
        $page = $this->addPage($flyer, 'flyers/'.$flyer->id.'/p1.webp');
        Storage::disk('r2')->put('flyers/'.$flyer->id.'/p1.webp', 'fake-page-binary');

        $oldItem = $this->addItem($flyer, [
            'product_name' => 'سلعة قديمة ستستبدل',
            'slug' => 'old-item-'.uniqid(),
            'sale_price' => 10.0,
        ]);

        $ocr = json_encode(['items' => [
            ['product_name' => 'زيت عافية 1.5 لتر', 'sale_price' => 90.5, 'old_price' => 120.0, 'unit' => '1.5 لتر', 'category' => 'oils_ghee', 'brand_name' => 'عافية', 'bundle_condition' => null, 'extra_attributes' => []],
            // Intra-page duplicate (same stem + price) → Richest-Row dedup.
            ['product_name' => 'زيت عافية 1.5 لتر', 'sale_price' => 90.5, 'old_price' => 120.0, 'unit' => '1.5 لتر', 'category' => 'oils_ghee', 'brand_name' => 'عافية', 'bundle_condition' => null, 'extra_attributes' => []],
            ['product_name' => 'سكر 1 كجم', 'sale_price' => 41.95, 'old_price' => null, 'unit' => '1 كجم', 'category' => 'groceries', 'brand_name' => null, 'bundle_condition' => null, 'extra_attributes' => []],
            // Zero-price row must never survive.
            ['product_name' => 'سلعة بدون سعر', 'sale_price' => 0, 'old_price' => null, 'unit' => null, 'category' => 'other', 'brand_name' => null, 'bundle_condition' => null, 'extra_attributes' => []],
        ]], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->candidate($ocr), 200)
                ->push($this->candidate($this->blufText()), 200)
                ->push($this->candidate($this->editorialText()), 200),
        ]);

        Queue::fake();

        (new RegenerateFlyerContentJob($flyer->id, 'full'))->handle(app(GeminiVisionService::class));

        $flyer->refresh();

        // SEO slug untouched.
        $this->assertSame('kazyon-regen-offer', $flyer->slug);

        // Old item replaced; duplicate collapsed; zero-price excluded → 2 rows.
        $this->assertDatabaseMissing('flyer_items', ['id' => $oldItem->id]);
        $this->assertSame(2, FlyerItem::query()->where('flyer_id', $flyer->id)->count());

        $oil = FlyerItem::query()->where('flyer_id', $flyer->id)->where('product_name', 'like', '%زيت عافية%')->first();
        $this->assertNotNull($oil);
        $this->assertSame($page->id, $oil->flyer_page_id);
        $this->assertEqualsWithDelta(24.58, (float) $oil->discount_percent, 0.01);
        $this->assertEqualsWithDelta(29.5, (float) $oil->savings_amount, 0.01);
        $this->assertNotSame('', (string) $oil->normalized_name);

        // Copy rebuilt from the NEW items.
        $this->assertStringContainsString('بخصومات تصل إلى 25%', (string) $flyer->bluf_summary);
        $this->assertStringContainsString('زيت عافية', (string) $flyer->editorial_overview);

        // OCR consumed the STORED R2 binary — never a Facebook CDN refetch.
        Http::assertSent(static function ($request): bool {
            return str_contains((string) json_encode($request->data()), base64_encode('fake-page-binary'));
        });

        // FlyerObserver purged the CDN edge + sitemap/llms on the audited save.
        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job): bool {
            $this->assertContains('https://magalatna.com/offers/kazyon-regen-offer', $job->urls);
            $this->assertContains('https://magalatna.com/sitemap.xml', $job->urls);
            $this->assertContains('https://magalatna.com/llms.txt', $job->urls);

            return true;
        });
    }

    public function test_full_mode_zero_priced_items_preserves_existing_items(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'kazyon-zero-guard',
            'discover_image_path' => 'flyers/discover-preset.webp',
        ]);
        $this->addPage($flyer, 'flyers/'.$flyer->id.'/p1.webp');
        Storage::disk('r2')->put('flyers/'.$flyer->id.'/p1.webp', 'fake-page-binary');

        $existing = $this->addItem($flyer, ['slug' => 'keep-me-'.uniqid()]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->candidate(json_encode(['items' => [
                    ['product_name' => 'سلعة بدون سعر', 'sale_price' => 0],
                ]], JSON_UNESCAPED_UNICODE)),
                200,
            ),
        ]);

        try {
            (new RegenerateFlyerContentJob($flyer->id, 'full'))->handle(app(GeminiVisionService::class));
            $this->fail('Expected RuntimeException when full regeneration extracts 0 priced items.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }

        // ZERO DATA LOSS: the live flyer's items are intact.
        $this->assertDatabaseHas('flyer_items', ['id' => $existing->id]);
        $this->assertSame(1, FlyerItem::query()->where('flyer_id', $flyer->id)->count());
        $this->assertSame('kazyon-zero-guard', $flyer->refresh()->slug);
    }

    public function test_full_mode_ocr_http_failure_preserves_existing_items(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'kazyon-fail-guard',
            'discover_image_path' => 'flyers/discover-preset.webp',
        ]);
        $this->addPage($flyer, 'flyers/'.$flyer->id.'/p1.webp');
        Storage::disk('r2')->put('flyers/'.$flyer->id.'/p1.webp', 'fake-page-binary');

        $existing = $this->addItem($flyer, ['slug' => 'keep-me-too-'.uniqid()]);

        // Every model answers 500 → waterfall exhausts → RuntimeException.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'backend down']], 500),
        ]);

        try {
            (new RegenerateFlyerContentJob($flyer->id, 'full'))->handle(app(GeminiVisionService::class));
            $this->fail('Expected RuntimeException when every OCR model fails.');
        } catch (RuntimeException $e) {
            // expected — the swap transaction never ran
        }

        $this->assertDatabaseHas('flyer_items', ['id' => $existing->id]);
        $this->assertSame(1, FlyerItem::query()->where('flyer_id', $flyer->id)->count());
    }

    public function test_copy_mode_updates_texts_without_ocr(): void
    {
        $flyer = $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'kazyon-copy-mode',
            'bluf_summary' => 'نص قديم جدا',
            'discover_image_path' => 'flyers/discover-preset.webp',
        ]);
        $this->addPage($flyer, 'flyers/'.$flyer->id.'/p1.webp');
        $itemA = $this->addItem($flyer, ['slug' => 'copy-a-'.uniqid()]);
        $itemB = $this->addItem($flyer, ['product_name' => 'سكر 1 كجم', 'slug' => 'copy-b-'.uniqid(), 'sale_price' => 41.95, 'old_price' => null, 'discount_percent' => null]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->candidate($this->blufText()), 200)
                ->push($this->candidate($this->editorialText()), 200),
        ]);

        Queue::fake();

        (new RegenerateFlyerContentJob($flyer->id, 'copy'))->handle(app(GeminiVisionService::class));

        $flyer->refresh();

        $this->assertStringContainsString('بخصومات تصل إلى 25%', (string) $flyer->bluf_summary);
        $this->assertStringContainsString('زيت عافية', (string) $flyer->editorial_overview);
        $this->assertSame('kazyon-copy-mode', $flyer->slug);

        // Items untouched — copy mode never re-OCRs.
        $this->assertSame(2, FlyerItem::query()->where('flyer_id', $flyer->id)->count());
        $this->assertDatabaseHas('flyer_items', ['id' => $itemA->id]);
        $this->assertDatabaseHas('flyer_items', ['id' => $itemB->id]);

        // Copy mode is now 100% deterministic — no Gemini HTTP calls at all.
        Http::assertSentCount(0);

        Queue::assertPushed(PurgeCloudflareCacheJob::class);
    }

    public function test_command_validates_input_and_dispatches_job(): void
    {
        Queue::fake();

        $flyer = $this->createPublishedFlyer();

        $this->artisan('flyers:regenerate', ['flyer_id' => $flyer->id, '--mode' => 'copy'])
            ->assertSuccessful();

        Queue::assertPushed(RegenerateFlyerContentJob::class, function (RegenerateFlyerContentJob $job) use ($flyer): bool {
            return $job->flyerId === $flyer->id && $job->mode === 'copy';
        });

        $this->artisan('flyers:regenerate', ['flyer_id' => 999999])->assertFailed();
        $this->artisan('flyers:regenerate', ['flyer_id' => $flyer->id, '--mode' => 'bogus'])->assertFailed();
    }
}
