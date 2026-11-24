<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Brand;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Services\ProductDataNormalizer;
use App\Support\ArabicNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 1 retroactive data cleanup: every flyer produced BEFORE the post-OCR
 * normalization pipeline shipped carries Gemini's raw output — misread units
 * (ا كجم), brand spelling drift (دولتين), no category, and cover-vs-inner
 * duplicate rows. This command re-applies the pipeline to ALL existing items
 * (normalize → categorize → Richest-Row dedup), rebuilds the SEO copy with
 * the DETERMINISTIC fallback builders, and purges the CDN for the affected
 * live pages.
 */
final class CleanFlyerItemsCommand extends Command
{
    protected $signature = 'flyers:clean-items
        {--dry-run : عرض التغييرات المقترحة دون حفظ أي بيانات}
        {--brands-only : تشغيل إصلاح البراندات المخالفة فقط (إيليت/إلفيف/دولتين/بيبيسي) ثم الخروج}
        {--repair-titles : إصلاح عناوين المجلات المقصوصة وسط الكلمة (| تخفيضا، | مه) ثم الخروج}
        {--ai-audit : تشغيل مدقق النصوص الذكي (Gemini 3.5 Flash Lite) بعد التنظيف}';

    protected $description = 'تنظيف وتوحيد بيانات سلع المجلات: تصحيح أسماء المنتجات والبراندات، التصنيف التلقائي، وإزالة التكرار (أغنى صف يفوز) مع إعادة بناء النصوص الترويجية';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // One-time legacy brand-cleanup run: fix the historical OCR
        // misassignments (Gillette→إيليت, L'Oréal/إفيف→إلفيف, دولتين→دولفين,
        // بيبيسي→بيبسي) WITHOUT re-running the whole per-flyer pipeline.
        if ((bool) $this->option('brands-only')) {
            return $this->cleanMismatchedBrands($dryRun);
        }

        // One-time legacy title repair: rebuild titles the OLD 65-char
        // cutter chopped MID-WORD (sitemap image:title shows "| تخفيضا",
        // "| مه") BEFORE the word-boundary truncation shipped.
        if ((bool) $this->option('repair-titles')) {
            return $this->repairTruncatedTitles($dryRun);
        }

        // AI Auditor only mode: run the text auditor on all flyers.
        if ((bool) $this->option('ai-audit')) {
            return $this->runAiAuditor($dryRun);
        }

        $flyerIds = Flyer::query()
            ->whereHas('items')
            ->orderBy('id')
            ->pluck('id');

        $total = $flyerIds->count();

        if ($total === 0) {
            $this->info('لا توجد مجلات تحتوي على سلع للتنظيف.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "بدء الفحص التجريبي لـ {$total} مجلة (لن تُحفظ أي تغييرات)..."
            : "بدء تنظيف {$total} مجلة...");

        $stats = [
            'flyers' => 0,
            'items_renamed' => 0,
            'brands_resolved' => 0,
            'items_categorized' => 0,
            'duplicates_removed' => 0,
            'flyers_changed' => 0,
            'copy_rebuilt' => 0,
        ];

        /** @var list<int> $changedFlyerIds flyers whose PUBLIC page must be purged */
        $changedFlyerIds = [];

        foreach ($flyerIds->chunk(25) as $chunk) {
            /** @var \Illuminate\Support\Collection<int, Flyer> $flyers */
            $flyers = Flyer::query()
                ->with(['items', 'retailer'])
                ->whereIn('id', $chunk->all())
                ->orderBy('id')
                ->get();

            foreach ($flyers as $flyer) {
                $flyerChanged = false;

                try {
                    // One transaction per flyer: a normalize failure rolls back
                    // THIS flyer only and never poisons the rest of the chunk.
                    DB::transaction(function () use ($flyer, $dryRun, &$stats, &$changedFlyerIds, &$flyerChanged): void {
                        $itemsChanged = false;

                        foreach ($flyer->items as $item) {
                            /** @var FlyerItem $item */
                            $dirty = false;
                            $originalName = trim((string) $item->product_name);

                            // --- 1. Product-name normalization -----------------
                            $cleaned = $originalName === '' ? '' : ProductDataNormalizer::cleanProductName($originalName);

                            if ($cleaned !== '' && $cleaned !== $originalName) {
                                // saveQuietly() skips FlyerItemObserver, which is
                                // what normally fills normalized_name — update it
                                // MANUALLY alongside the rename.
                                $item->product_name = $cleaned;
                                $item->normalized_name = ArabicNormalizer::normalize($cleaned) ?: $cleaned;
                                $dirty = true;
                                $stats['items_renamed']++;
                            }

                            $productName = $cleaned !== '' ? $cleaned : $originalName;

                            // --- 2. Brand canonicalization ---------------------
                            $currentBrandName = $item->brand?->name;
                            $canonicalBrand = ProductDataNormalizer::canonicalBrandName(
                                $currentBrandName !== null ? trim((string) $currentBrandName) : null,
                                $productName,
                            );

                            if (is_string($canonicalBrand) && trim($canonicalBrand) !== ''
                                && ($currentBrandName === null || trim((string) $currentBrandName) !== trim($canonicalBrand))) {
                                $brandId = $this->resolveBrandId(trim($canonicalBrand));

                                if ($brandId !== null && $brandId !== $item->brand_id) {
                                    $item->brand_id = $brandId;
                                    $dirty = true;
                                    $stats['brands_resolved']++;
                                }
                            }

                            // --- 3. Deterministic categorization ---------------
                            $category = ProductDataNormalizer::inferCategory($productName);

                            if ((string) $item->category !== $category) {
                                $item->category = $category;
                                $dirty = true;
                                $stats['items_categorized']++;
                            }

                            if ($dirty && ! $dryRun) {
                                $item->saveQuietly();
                                $itemsChanged = true;
                            } elseif ($dirty) {
                                $itemsChanged = true;
                            }
                        }

                        // --- 4. Richest-Row Winner dedup -----------------------
                        if (! $dryRun) {
                            // (survivor saves + deletions happen inside)
                            $removed = GatekeeperFacebookPostJob::dedupFlyerItems($flyer);
                            $stats['duplicates_removed'] += $removed;

                            if ($removed > 0) {
                                $itemsChanged = true;
                            }
                        } else {
                            // Dry-run: count would-be duplicates WITHOUT touching the DB.
                            $deduplicatable = $flyer->items
                                ->filter(static fn (FlyerItem $item): bool => trim((string) $item->product_name) !== '');

                            if ($deduplicatable->count() >= 2) {
                                /** @var array{kept: list<FlyerItem>, removedIds: list<int>} $result */
                                $result = ProductDataNormalizer::deduplicate($deduplicatable->values()->all());
                                $stats['duplicates_removed'] += count($result['removedIds']);

                                if ($result['removedIds'] !== []) {
                                    $itemsChanged = true;
                                }
                            }
                        }

                        if ($itemsChanged) {
                            $flyerChanged = true;
                            $stats['flyers_changed']++;
                            $changedFlyerIds[] = (int) $flyer->id;
                        }

                        $stats['flyers']++;
                    });

                    // --- 5. Rebuild BLUF + editorial (deterministic fallback) ---
                    if ($flyerChanged && ! $dryRun) {
                        // The in-memory `items` collection is now STALE: the
                        // dedup step just deleted rows the collection still
                        // holds. Drop the relation so every rebuild access
                        // ($flyer->items()) hits the DB fresh and the new
                        // copy never quotes deleted/merged duplicates.
                        $flyer->unsetRelation('items');

                        // Blank the Gemini key: generateBlufSummary()/Editorial()
                        // throw immediately in apiKey(), the Gatekeeper builders
                        // catch it, and the DETERMINISTIC fallback copy is built —
                        // no AI cost, no rate limits, fully reproducible output.
                        config()->set('services.gemini.key', '');

                        $flyer->bluf_summary = GatekeeperFacebookPostJob::buildBlufSummary($flyer);
                        $flyer->editorial_overview = GatekeeperFacebookPostJob::buildEditorialOverview($flyer);
                        $flyer->saveQuietly();
                        $stats['copy_rebuilt']++;
                    }
                } catch (Throwable $e) {
                    Log::error('CleanFlyerItemsCommand: flyer cleanup failed.', [
                        'flyer_id' => $flyer->id,
                        'dry_run' => $dryRun,
                        'error' => $e->getMessage(),
                    ]);

                    $this->warn("فشل تنظيف المجلة #{$flyer->id}: {$e->getMessage()}");
                }
            }

            // Memory hygiene: release the hydrated chunk before the next one.
            unset($flyers);
            gc_collect_cycles();
        }

        // --- 5b. Prune obsolete non-canonical Brand rows (real writes only) ---
        if (! $dryRun) {
            $this->pruneObsoleteBrands();
        }

        // --- 6. Cache invalidation + CDN purge (only when really writing) -------
        if (! $dryRun) {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            $base = rtrim((string) config('app.url'), '/');
            $allUrls = [$base.'/', $base.'/sitemap.xml', $base.'/llms.txt'];

            // Only ACTIVE published flyers changed public content — expired or
            // pending pages are not indexable/purge-worthy.
            $published = Flyer::query()
                ->with('retailer')
                ->whereIn('id', $changedFlyerIds)
                ->where('status', \App\Enums\FlyerStatus::Published->value)
                ->get();

            foreach ($published as $flyer) {
                $allUrls[] = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                if ($flyer->retailer !== null && (string) $flyer->retailer->slug !== '') {
                    $allUrls[] = $base.route('retailers.show', ['retailer' => (string) $flyer->retailer->slug], false);
                }
            }

            $allUrls = array_values(array_unique(array_filter(array_map(
                static fn (mixed $u): string => trim((string) $u),
                $allUrls,
            ))));

            // Respect Cloudflare Edge API batch limits: max 30 URLs per purge.
            foreach (array_chunk($allUrls, 30) as $index => $chunk) {
                try {
                    PurgeCloudflareCacheJob::dispatch($chunk);
                } catch (Throwable $e) {
                    Log::error('CleanFlyerItemsCommand: failed to dispatch purge chunk.', [
                        'chunk' => $index,
                        'urls_count' => count($chunk),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $mode = $dryRun ? '(تجريبي — لم يُحفظ شيء)' : '';

        $this->info("تمت المعالجة {$stats['flyers']}/{$total} مجلة {$mode}");
        $this->table(['العملية', 'العدد'], [
            ['أسماء منتجات تم تصحيحها', (string) $stats['items_renamed']],
            ['براندات تم توحيدها', (string) $stats['brands_resolved']],
            ['تصنيفات تم تعيينها', (string) $stats['items_categorized']],
            ['صفوف مكررة تم حذفها', (string) $stats['duplicates_removed']],
            ['مجلات متأثرة', (string) $stats['flyers_changed']],
            ['نصوص ترويجية أعيد بناؤها', (string) $stats['copy_rebuilt']],
        ]);

        Log::info('CleanFlyerItemsCommand finished.', $stats + ['dry_run' => $dryRun]);

        return self::SUCCESS;
    }

    /**
     * One-time legacy brand mismatch cleanup (معظمها من مجلات قبل خط
     * التطبيع): item rows whose PRODUCT NAME clearly names a brand while
     * their brand_id still points at the OCR-misread one.
     *
     *  - 'إيليت' (Elite) items mislabeled 'جيليت'/'Gillette' → إيليت
     *    (Gillette products never print "إيليت" — it is the Elite/Chewing
     *    gum… no, Elite is the chocolate/gum brand; the OCR read "إيليت"
     *    as "جيليت" and the auto-linker followed).
     *  - 'إلفيف'/'إفيف' (L'Oréal Elvive) items → إلفيف (never جيليت).
     *  - remaining 'دولتين' items → دولفين.
     *  - remaining 'بيبيسي' items → بيبسي.
     *
     * Runs as bulk UPDATEs (brand_id only — names/normalized_name untouched,
     * so FlyerItemObserver re-runs are unnecessary) and invalidates the
     * public caches afterwards.
     */
    public function cleanMismatchedBrands(bool $dryRun = false): int
    {
        // Canonical Brand rows (firstOrCreate so a missing canonical row is
        // materialized before any item is re-pointed at it).
        $brandIds = [];
        foreach (['إلفيف', 'إيليت', 'دولفين', 'بيبسي'] as $canonicalName) {
            $brandIds[$canonicalName] = $this->resolveBrandId($canonicalName);
        }

        // Wrong-source brand ids, resolved by NAME (never by item scan):
        $wrongGilletteIds = Brand::query()
            ->whereIn('name', ['جيليت', 'Gillette', 'gillette'])
            ->pluck('id');

        $fixes = [
            [
                'label' => 'إيليت (كان يشير إلى جيليت/Gillette)',
                'names' => ['%إيليت%'],
                'wrongIds' => $wrongGilletteIds,
                'target' => 'إيليت',
            ],
            [
                'label' => 'إلفيف/إفيف (أي براند آخر أو بدون براند) → إلفيف',
                'names' => ['%إلفيف%', '%إفيف%'],
                // ANY current brand (or none) other than إلفيف itself is a
                // legacy misassignment for items explicitly named إلفيف/إفيف.
                'wrongIds' => null,
                'target' => 'إلفيف',
            ],
            [
                'label' => 'دولتين → دولفين',
                'names' => ['%دولتين%'],
                'wrongIds' => null, // ANY current brand (incl. null) is wrong
                'target' => 'دولفين',
            ],
            [
                'label' => 'بيبيسي → بيبسي',
                'names' => ['%بيبيسي%'],
                'wrongIds' => null,
                'target' => 'بيبسي',
            ],
        ];

        $stats = [];

        foreach ($fixes as $fix) {
            $targetId = $brandIds[$fix['target']];

            if ($targetId === null) {
                $this->warn("تعذر إنشاء براند قياسي «{$fix['target']}» — تم التخطي.");

                continue;
            }

            $query = FlyerItem::query()
                ->where(static function ($q) use ($fix): void {
                    foreach ($fix['names'] as $needle) {
                        $q->orWhere('product_name', 'like', $needle);
                    }
                })
                ->where(static function ($q) use ($fix, $targetId): void {
                    if ($fix['wrongIds'] === null) {
                        // Any brand that is not already the canonical target.
                        $q->whereNull('brand_id')->orWhere('brand_id', '!=', $targetId);
                    } elseif ($fix['wrongIds']->isNotEmpty()) {
                        $q->whereIn('brand_id', $fix['wrongIds']->all());
                    } else {
                        $q->whereRaw('1 = 0'); // no wrong brands exist — no-op
                    }
                });

            $count = $query->count();
            $stats[$fix['label']] = $count;

            if ($count > 0 && ! $dryRun) {
                $query->update(['brand_id' => $targetId]);
                $this->info("تم تصحيح {$count} سلعة: {$fix['label']}.");
            } elseif ($count > 0) {
                $this->line("(تجريبي) {$count} سلعة ستُصحح: {$fix['label']}.");
            }
        }

        if (! $dryRun && array_sum($stats) > 0) {
            // Brand rows re-pointed → auto-linker map + sitemap caches stale.
            Cache::forget('auto_linker:brands');
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            Log::info('CleanFlyerItemsCommand: mismatched brands re-pointed.', $stats);
        }

        $mode = $dryRun ? '(تجريبي — لم يُحفظ شيء)' : '';
        $this->info('انتهى إصلاح البراندات المخالفة '.$mode);

        return self::SUCCESS;
    }

    /**
     * One-time truncated-title repair (عناوين قُصّت وسط الكلمة بـ 65 حرفاً
     * قبل وصول القصّ عند حدود الكلمة): a title segment after its LAST "|"
     * shorter than 8 Arabic chars (or a strict prefix of a known theme)
     * is a broken theme fragment — rebuild it word-safe:
     *  - snap the fragment to the FULL known theme when it is its prefix
     *    ("الجمعة البي" → "الجمعة البيضاء");
     *  - otherwise fall back to the canonical default theme
     *    "مجلة العروض والتوفير";
     *  - strip the Latin "(El Fergany)" parenthetical from the retailer
     *    name so full Arabic words keep their room;
     *  - the date segment before the "|" is UNTOUCHED (it was never cut).
     */
    public function repairTruncatedTitles(bool $dryRun = false): int
    {
        $knownThemes = [
            'مجلة العروض والتوفير',
            'العودة للمدارس',
            'الجمعة البيضاء',
            'رمضان',
            'العيد',
            'الصيف',
            'الشتاء',
        ];

        /** @var \Illuminate\Support\Collection<int, Flyer> $flyers */
        $flyers = Flyer::query()->with('retailer:id,name')->orderBy('id')->get(['id', 'retailer_id', 'title']);

        $stats = ['checked' => $flyers->count(), 'repaired' => 0];
        $changedFlyerIds = [];

        foreach ($flyers as $flyer) {
            $title = trim((string) $flyer->title);

            $pipePos = mb_strrpos($title, '|');
            if ($pipePos === false) {
                continue; // no theme segment — the old cut never hit it
            }

            $fragment = trim(mb_substr($title, $pipePos + 1));
            if ($fragment === '') {
                continue;
            }

            // Fragment recovery: complete known theme → untouched. A SHORT
            // fragment (< 8 chars) is mid-word debris ("تخفيضا", "مه"):
            // snap it to the FULL known theme it prefixes, else the
            // canonical default theme. Longer fragments (≥ 8) may be
            // perfectly valid short themes ("مجلة العروض") — never touched,
            // rewriting a plausible complete theme is worse than leaving
            // rare debris behind.
            $isComplete = false;
            foreach ($knownThemes as $theme) {
                if ($fragment === $theme) {
                    $isComplete = true;

                    break;
                }
            }

            if ($isComplete || mb_strlen($fragment) >= 8) {
                continue;
            }

            $fixedTheme = 'مجلة العروض والتوفير';
            foreach ($knownThemes as $theme) {
                if (str_starts_with($theme, $fragment)) {
                    $fixedTheme = $theme;

                    break;
                }
            }

            $prefix = trim(mb_substr($title, 0, $pipePos));
            $retailerName = (string) ($flyer->retailer?->name ?? '');

            // Same cap the live builder applies (Latin parenthetical out
            // first, word-boundary cut as the last resort). When the FULL
            // theme no longer fits under 65 even without the Latin name,
            // fall back to a SHORT truthful theme instead of letting the
            // cap chop the theme tail word-by-word.
            $newTitle = null;
            foreach (array_unique([$fixedTheme, 'مجلة العروض والتوفير', 'تخفيضات']) as $themeCandidate) {
                $candidate = GatekeeperFacebookPostJob::applyTitleLengthCap($prefix.' | '.$themeCandidate, $retailerName);

                if (mb_strlen($candidate) <= 65) {
                    $newTitle = $candidate;

                    break;
                }

                $newTitle = $candidate; // word-boundary-cut fallback
            }

            if ($newTitle === $title) {
                continue;
            }

            $stats['repaired']++;
            $changedFlyerIds[] = (int) $flyer->id;

            if ($dryRun) {
                $this->line("(تجريبي) #{$flyer->id}: «{$title}» → «{$newTitle}».");
            } else {
                $flyer->title = $newTitle;
                $flyer->saveQuietly();
                $this->info("تم إصلاح #{$flyer->id}: «{$newTitle}».");
            }
        }

        if (! $dryRun && $stats['repaired'] > 0) {
            // Titles are public content: sitemap image:title, <title> tags,
            // and llms.txt all read the stored column.
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            $base = rtrim((string) config('app.url'), '/');
            $allUrls = [$base.'/sitemap.xml'];

            $published = Flyer::query()
                ->with('retailer')
                ->whereIn('id', $changedFlyerIds)
                ->where('status', \App\Enums\FlyerStatus::Published->value)
                ->get();

            foreach ($published as $flyer) {
                $allUrls[] = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                if ($flyer->retailer !== null && (string) $flyer->retailer->slug !== '') {
                    $allUrls[] = $base.route('retailers.show', ['retailer' => (string) $flyer->retailer->slug], false);
                }
            }

            foreach (array_chunk(array_values(array_unique(array_filter($allUrls))), 30) as $index => $chunk) {
                try {
                    PurgeCloudflareCacheJob::dispatch($chunk);
                } catch (Throwable $e) {
                    Log::error('CleanFlyerItemsCommand: title-repair purge dispatch failed.', [
                        'chunk' => $index,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('CleanFlyerItemsCommand: truncated titles repaired.', $stats);
        }

        $mode = $dryRun ? '(تجريبي — لم يُحفظ شيء)' : '';
        $this->info("فحص {$stats['checked']} عنوان — إصلاح {$stats['repaired']} {$mode}");

        return self::SUCCESS;
    }

    /**
     * Delete alias-spelling Brand rows that canonicalization orphaned:
     * a Brand whose name is a NON-canonical alias key in
     * config('ocr_aliases.brand_aliases') (e.g. "دولتين" → canonical
     * "دولفين") and that now has ZERO flyerItems is an OCR-misread
     * spelling, not a real brand. Identity mappings ("كريستال" →
     * "كريستال") are canonical and never pruned. Name comparison is done
     * on Arabic-normalized lowercase keys — the brands table is small, so
     * a chunked scan with in-memory normalization is the pragmatic check.
     */
    private function pruneObsoleteBrands(): void
    {
        $normalizeKey = static fn (string $value): string => mb_strtolower(
            trim(ArabicNormalizer::normalize(ArabicNormalizer::toWesternDigits($value))),
        );

        /** @var array<string, true> $nonCanonicalKeys normalized alias keys that map to a DIFFERENT canonical name */
        $nonCanonicalKeys = [];
        foreach ((array) config('ocr_aliases.brand_aliases', []) as $raw => $canonical) {
            $rawKey = $normalizeKey((string) $raw);
            $canonicalKey = $normalizeKey((string) $canonical);

            if ($rawKey !== '' && $rawKey !== $canonicalKey) {
                $nonCanonicalKeys[$rawKey] = true;
            }
        }

        if ($nonCanonicalKeys === []) {
            return;
        }

        $pruned = 0;

        Brand::query()
            ->doesntHave('flyerItems')
            ->select(['id', 'name'])
            ->chunkById(200, static function ($brands) use ($nonCanonicalKeys, $normalizeKey, &$pruned): void {
                foreach ($brands as $brand) {
                    if (isset($nonCanonicalKeys[$normalizeKey((string) $brand->name)])) {
                        $brand->delete();
                        $pruned++;
                    }
                }
            });

        // BrandObserver already invalidates the auto-linker map on delete,
        // but this explicit forget guarantees a clean rebuild even if a
        // future refactor drops the observer.
        Cache::forget('auto_linker:brands');

        if ($pruned > 0) {
            $this->info("تم حذف {$pruned} براند قديم (تهجئة غير قياسية بدون سلع).");
        }

        Log::info('CleanFlyerItemsCommand: obsolete alias brands pruned.', ['pruned' => $pruned]);
    }

    /**
     * Resolve a canonical brand name to a `brands` row — the SAME
     * firstOrCreate + unique-slug pattern ProcessSinglePageJob uses.
     */
    private function resolveBrandId(string $canonicalName): ?int
    {
        $brandSlug = Str::slug($canonicalName);

        if ($brandSlug === '') {
            $brandSlug = 'brand-'.Str::lower(Str::ulid()->toString());
        }

        try {
            $brand = Brand::firstOrCreate(
                ['name' => $canonicalName],
                ['slug' => $this->uniqueBrandSlug($brandSlug)],
            );

            return (int) $brand->id;
        } catch (Throwable $e) {
            // Unique-constraint race with a concurrent worker: re-query.
            try {
                $brand = Brand::query()->where('name', $canonicalName)->first();

                return $brand?->id;
            } catch (Throwable $re) {
                Log::warning('CleanFlyerItemsCommand: brand resolution failed.', [
                    'brand' => $canonicalName,
                    'error' => $re->getMessage(),
                ]);

                return null;
            }
        }
    }

    private function uniqueBrandSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 0;

        while (Brand::where('slug', $slug)->exists()) {
            $counter++;
            $slug = $baseSlug.'-'.$counter;

            if ($counter > 10) {
                $slug = $baseSlug.'-'.Str::lower(Str::ulid()->toString());

                break;
            }
        }

        return $slug;
    }

    /**
     * Run the AI Text Auditor on all flyers with items.
     */
    private function runAiAuditor(bool $dryRun): int
    {
        $flyerIds = Flyer::query()
            ->whereHas('items')
            ->orderBy('id')
            ->pluck('id');

        $total = $flyerIds->count();

        if ($total === 0) {
            $this->info('لا توجد مجلات تحتوي على سلع للتدقيق.');

            return self::SUCCESS;
        }

        $this->info("بدء تدقيق نصوص {$total} مجلة...");

        $auditor = app(\App\Services\FlyerItemsAiAuditorService::class);
        $stats = ['flyers' => 0, 'fixes' => 0, 'drops' => 0, 'skipped' => 0];

        foreach ($flyerIds->chunk(25) as $chunk) {
            $flyers = Flyer::query()
                ->with(['items', 'retailer'])
                ->whereIn('id', $chunk->all())
                ->orderBy('id')
                ->get();

            foreach ($flyers as $flyer) {
                try {
                    $result = $dryRun
                        ? ['applied' => 0, 'dropped' => 0, 'skipped' => false] // Dry run: just log what would happen
                        : $auditor->audit($flyer);

                    $stats['flyers']++;
                    $stats['fixes'] += $result['applied'];
                    $stats['drops'] += $result['dropped'];
                    if ($result['skipped']) {
                        $stats['skipped']++;
                    }

                    if (! $result['skipped'] && ($result['applied'] > 0 || $result['dropped'] > 0)) {
                        $this->line("  #{$flyer->id}: {$result['applied']} تصحيحات، {$result['dropped']} محذوفات");
                    }
                } catch (Throwable $e) {
                    $this->warn("  #{$flyer->id}: خطأ - {$e->getMessage()}");
                }
            }

            unset($flyers);
            gc_collect_cycles();
        }

        $this->info("انتهى التدقيق: {$stats['flyers']} مجلة، {$stats['fixes']} تصحيح، {$stats['drops']} حذف، {$stats['skipped']} متخطاة.");

        return self::SUCCESS;
    }
}
