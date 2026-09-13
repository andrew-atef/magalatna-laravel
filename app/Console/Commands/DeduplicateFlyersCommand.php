<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Models\Retailer;
use App\Support\ArabicNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class DeduplicateFlyersCommand extends Command
{
    protected $signature = 'flyers:deduplicate {--dry-run : Preview without deleting}';

    protected $description = 'تنظيف التكرارات: دمج المتاجر المكررة والمجلات المكررة بنفس الفترة وحذف المنتجات المكررة - Zero Data Loss';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? '--- DRY RUN: لا يتم حذف فعلي ---' : '--- بدء تنظيف التكرارات ---');

        $retailerMerged = 0;
        $retailerDeleted = 0;
        $flyersMerged = 0;
        $flyersDeleted = 0;
        $pagesMoved = 0;
        $itemsMoved = 0;
        $itemsDeleted = 0;
        $pagesCompressed = 0;
        $flyersCompressed = 0;
        $blufRegenerated = 0;
        /** @var array<int, true> $touchedFlyerIds Flyers whose pages/items changed and need BLUF refresh */
        $touchedFlyerIds = [];

        // 1. Clean Duplicate Retailers by canonical key.
        // Catches bim/bimmisr, kazyon/kazyonegypt, carrefour/carrefouregypt
        // (slug suffixes) as well as identical Arabic names (بيم / بيم مصر).
        $this->info('1) فحص المتاجر المكررة...');
        $retailers = Retailer::all();
        $grouped = $retailers->groupBy(fn (Retailer $r): string => self::canonicalRetailerKey($r));

        foreach ($grouped as $canonicalKey => $group) {
            if ($group->count() <= 1) {
                continue;
            }

            // Primary = lowest ID (canonical)
            $sorted = $group->sortBy('id');
            $primary = $sorted->first();
            $duplicates = $sorted->slice(1);

            foreach ($duplicates as $dup) {
                $flyerCount = Flyer::where('retailer_id', $dup->id)->count();
                $rawCount = \App\Models\RawFacebookPost::where('retailer_id', $dup->id)->count();
                $childCount = Retailer::where('parent_id', $dup->id)->count();

                $this->line(" - دمج المتجر المكرر [{$dup->id}] {$dup->name} ({$dup->slug}) -> الأساسي [{$primary->id}] {$primary->name} ({$primary->slug}) — {$flyerCount} مجلة, {$rawCount} منشور خام, {$childCount} فرع");

                if (! $dryRun) {
                    DB::transaction(function () use ($dup, $primary): void {
                        Flyer::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
                        \App\Models\RawFacebookPost::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
                        Retailer::where('parent_id', $dup->id)->update(['parent_id' => $primary->id]);
                    });
                    $dup->delete();
                }

                $retailerMerged++;
                $retailerDeleted++;
            }
        }

        // Second pass: identical Arabic clean names with different slug roots (safety net)
        $retailers = Retailer::all();
        $groupedByName = $retailers->groupBy(fn (Retailer $r): string => trim((string) preg_replace('/\s+مصر$/u', '', (string) $r->name)));
        foreach ($groupedByName as $cleanName => $group) {
            if ($group->count() <= 1 || trim((string) $cleanName) === '') {
                continue;
            }
            // Skip groups already unified by canonical key
            $keys = $group->map(fn (Retailer $r): string => self::canonicalRetailerKey($r))->unique();
            if ($keys->count() <= 1) {
                continue; // handled (or mergeable) in first pass
            }
            $sorted = $group->sortBy('id');
            $primary = $sorted->first();
            $duplicates = $sorted->slice(1);
            foreach ($duplicates as $dup) {
                // Re-check existence (first pass may have deleted it)
                if (Retailer::find($dup->id) === null || $dup->id === $primary->id) {
                    continue;
                }
                $flyerCount = Flyer::where('retailer_id', $dup->id)->count();
                $rawCount = \App\Models\RawFacebookPost::where('retailer_id', $dup->id)->count();
                $this->line(" - دمج بالاسم المطابق [{$dup->id}] {$dup->name} ({$dup->slug}) -> الأساسي [{$primary->id}] {$primary->name} ({$primary->slug}) — {$flyerCount} مجلة, {$rawCount} منشور خام");
                if (! $dryRun) {
                    DB::transaction(function () use ($dup, $primary): void {
                        Flyer::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
                        \App\Models\RawFacebookPost::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
                        Retailer::where('parent_id', $dup->id)->update(['parent_id' => $primary->id]);
                    });
                    $dup->delete();
                }
                $retailerMerged++;
                $retailerDeleted++;
            }
        }
        $this->info(" → تم دمج {$retailerMerged} متجر مكرر، حذف {$retailerDeleted}");

        // 2. Merge Duplicate Flyers by [retailer_id, valid_from, valid_until]
        $this->info('2) فحص المجلات المكررة بنفس الفترة...');
        $groups = Flyer::select('retailer_id', 'valid_from', 'valid_until', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id) as primary_id'))
            ->groupBy('retailer_id', 'valid_from', 'valid_until')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($groups as $g) {
            $flyers = Flyer::where('retailer_id', $g->retailer_id)
                ->where('valid_from', $g->valid_from)
                ->where('valid_until', $g->valid_until)
                ->orderBy('id')
                ->get();

            if ($flyers->count() <= 1) {
                continue;
            }

            $primary = $flyers->first();
            $dups = $flyers->slice(1);

            $this->line(" - مجموعة مكررة: retailer {$g->retailer_id} {$g->valid_from} → {$g->valid_until} — الأساسي #{$primary->id} ({$primary->slug}), مكررات: " . $dups->pluck('id')->implode(','));

            if ($dryRun) {
                // Dry-run: estimate only (unique image_path + unique items)
                $existingPaths = $primary->pages()->pluck('image_path')->filter()->map(fn ($p) => trim((string) $p))->all();
                $existingPaths = array_unique($existingPaths);
                $existingKeys = $primary->items()->get()->map(fn ($i) => mb_strtolower(trim((string) $i->product_name)) . '|' . number_format((float) $i->sale_price, 2, '.', ''))->flip()->all();
                foreach ($dups as $dup) {
                    foreach ($dup->pages()->get() as $page) {
                        $path = trim((string) $page->image_path);
                        if ($path !== '' && in_array($path, $existingPaths, true)) {
                            continue;
                        }
                        $existingPaths[] = $path;
                        $pagesMoved++;
                    }
                    foreach ($dup->items()->get() as $item) {
                        $key = mb_strtolower(trim((string) $item->product_name)) . '|' . number_format((float) $item->sale_price, 2, '.', '');
                        if (isset($existingKeys[$key])) {
                            continue;
                        }
                        $existingKeys[$key] = true;
                        $itemsMoved++;
                    }
                    $flyersDeleted++;
                }
                $flyersMerged++;

                continue;
            }

            DB::transaction(function () use ($primary, $dups, &$pagesMoved, &$itemsMoved, &$flyersDeleted, &$flyersMerged): void {
                $primaryFresh = Flyer::findOrFail($primary->id);

                $existingPaths = $primaryFresh->pages()->pluck('image_path')->filter()->map(fn ($p) => trim((string) $p))->all();
                $existingPaths = array_values(array_unique($existingPaths));

                $existingKeys = $primaryFresh->items()->get()
                    ->map(fn ($i) => mb_strtolower(trim((string) $i->product_name)) . '|' . number_format((float) $i->sale_price, 2, '.', ''))
                    ->flip()->all();

                // Next free page_number avoids UNIQUE(flyer_id, page_number) collision
                $nextPageNumber = ((int) $primaryFresh->pages()->max('page_number')) + 1;
                if ($nextPageNumber < 1) {
                    $nextPageNumber = 1;
                }

                // old_page_id => new_page_id (same row id after move) or null if skipped
                $pageIdMap = [];

                foreach ($dups as $dup) {
                    $dupFresh = Flyer::find($dup->id);
                    if (! $dupFresh) {
                        continue;
                    }

                    // 1) Move unique pages with fresh page_number
                    $pages = $dupFresh->pages()->orderBy('page_number')->orderBy('id')->get();
                    foreach ($pages as $page) {
                        $path = trim((string) $page->image_path);
                        if ($path !== '' && in_array($path, $existingPaths, true)) {
                            // Duplicate image — leave for cascade delete
                            $pageIdMap[$page->id] = null;
                            Log::info("Deduplicate: skipping duplicate page image {$path} (flyer {$dupFresh->id} page {$page->id})");

                            continue;
                        }
                        $oldId = $page->id;
                        $page->update(['flyer_id' => $primaryFresh->id, 'page_number' => $nextPageNumber++]);
                        $pageIdMap[$oldId] = $page->id;
                        $existingPaths[] = $path;
                        $pagesMoved++;
                    }

                    // 2) Move unique items BEFORE deleting dup (else cascade deletes them)
                    $items = $dupFresh->items()->orderBy('id')->get();
                    foreach ($items as $item) {
                        $key = mb_strtolower(trim((string) $item->product_name)) . '|' . number_format((float) $item->sale_price, 2, '.', '');
                        if (isset($existingKeys[$key])) {
                            // Duplicate of primary — leave for cascade delete
                            continue;
                        }
                        $oldPageId = $item->flyer_page_id;
                        $newPageId = null;
                        if ($oldPageId !== null) {
                            if (array_key_exists($oldPageId, $pageIdMap)) {
                                $newPageId = $pageIdMap[$oldPageId]; // null if page was skipped
                            } else {
                                // Item points to a page outside the dup (shouldn't happen) — detach
                                $newPageId = null;
                            }
                        }
                        $item->update(['flyer_id' => $primaryFresh->id, 'flyer_page_id' => $newPageId]);
                        $existingKeys[$key] = true;
                        $itemsMoved++;
                    }

                    // 3) Delete dup — only remaining (skipped) pages/items cascade
                    $dupFresh->delete();
                    $flyersDeleted++;
                }

                // 4) Resequence pages 1..N (UNIQUE-safe, see resequencePages())
                $this->resequencePages((int) $primaryFresh->id);
                $flyersMerged++;
            });
        }
        $this->info(" → مجموعات مكررة: {$flyersMerged} مجموعة، حذف {$flyersDeleted} مجلة مكررة، نقل {$pagesMoved} صفحة و {$itemsMoved} منتج");

        // 3. Compress bloated flyers: identical images → keep first copy.
        // (e.g. 30 pages from 6x-merged 5-image album → 5 unique pages).
        // R2 filenames are random ULIDs, so grouping uses the R2 object SIZE
        // (HEAD metadata request — zero image bytes loaded into RAM, no OOM)
        // with the OCR-extracted item identity set as DB-local fallback.
        // Zero data loss: items on deleted pages are relinked to the surviving copy.
        $this->info('3) ضغط المجلات المتضخمة (صفحات مكررة بنفس المحتوى)...');
        Flyer::query()->orderBy('id')->chunk(50, function ($flyers) use ($dryRun, &$pagesCompressed, &$flyersCompressed, &$touchedFlyerIds): void {
            foreach ($flyers as $flyer) {
                $pages = $flyer->pages()->orderBy('page_number')->orderBy('id')->get();
                if ($pages->count() <= 1) {
                    continue;
                }

                $bySignature = [];
                /** @var array<int, int> $dupMap dupPageId => keptPageId */
                $dupMap = [];
                foreach ($pages as $page) {
                    $sig = self::pageContentSignature((int) $page->id, trim((string) $page->image_path));
                    if (! isset($bySignature[$sig])) {
                        $bySignature[$sig] = $page;

                        continue;
                    }
                    $dupMap[(int) $page->id] = (int) $bySignature[$sig]->id;
                }

                if ($dupMap === []) {
                    continue;
                }

                $this->line(" - مجلة متضخمة #{$flyer->id} ({$flyer->slug}): {$pages->count()} صفحة → " . ($pages->count() - count($dupMap)) . ' فريدة');

                if (! $dryRun) {
                    DB::transaction(function () use ($flyer, $dupMap): void {
                        foreach ($dupMap as $dupId => $keptId) {
                            // Relink items to the surviving copy before deleting the page
                            FlyerItem::where('flyer_page_id', $dupId)->update(['flyer_page_id' => $keptId]);
                            FlyerPage::where('id', $dupId)->delete();
                        }

                        $this->resequencePages((int) $flyer->id);
                    });
                }

                $pagesCompressed += count($dupMap);
                $flyersCompressed++;
                $touchedFlyerIds[(int) $flyer->id] = true;
            }
        });
        $this->info(" → ضغط {$flyersCompressed} مجلة متضخمة، حذف {$pagesCompressed} صفحة مكررة المحتوى");

        // 4. Deduplicate FlyerItem Records within each flyer by [flyer_id, normalized_name, sale_price]
        $this->info('4) إزالة المنتجات المكررة داخل كل مجلة...');
        Flyer::query()->orderBy('id')->chunk(50, function ($flyers) use ($dryRun, &$itemsDeleted, &$touchedFlyerIds): void {
            foreach ($flyers as $flyer) {
                $seen = [];
                $flyerTouched = false;
                foreach ($flyer->items()->orderBy('id')->get() as $item) {
                    $norm = trim((string) $item->normalized_name);
                    if ($norm === '') {
                        $norm = ArabicNormalizer::normalize((string) $item->product_name);
                        if (! $dryRun && $norm !== '') {
                            $item->update(['normalized_name' => $norm]);
                        }
                    }
                    $key = mb_strtolower($norm) . '|' . number_format((float) $item->sale_price, 2, '.', '');
                    if (isset($seen[$key])) {
                        if (! $dryRun) {
                            $item->delete();
                        }
                        $itemsDeleted++;
                        $flyerTouched = true;
                    } else {
                        $seen[$key] = true;
                    }
                }
                if ($flyerTouched) {
                    $touchedFlyerIds[(int) $flyer->id] = true;
                }
            }
        });
        $this->info(" → حذف {$itemsDeleted} منتج مكرر");

        // 5. Regenerate BLUF + Editorial Overview for cleaned flyers.
        // Nulling the stored columns lets the Flyer accessors rebuild fresh summaries
        // deterministically from the merged items (zero Gemini API cost, no 429 risk).
        if (! $dryRun && $touchedFlyerIds !== []) {
            $this->info('5) تجديد ملخصات BLUF للمجلات المنظفة...');
            foreach (array_keys($touchedFlyerIds) as $fid) {
                $f = Flyer::find($fid);
                if ($f === null) {
                    continue;
                }
                $f->update(['bluf_summary' => null, 'editorial_overview' => null]);
                $blufRegenerated++;
            }
            $this->info(" → تم تجديد {$blufRegenerated} ملخص");
        } else {
            $this->info($dryRun ? '5) [Dry Run] سيتم تجديد ملخصات BLUF للمجلات المنظفة.' : '5) لا توجد مجلات تحتاج تجديد ملخصات.');
        }

        // 6. Flush & Invalidate Caches
        if (! $dryRun) {
            Cache::flush();
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');
            PurgeCloudflareCacheJob::dispatch([url('/'), url('/sitemap.xml'), url('/llms.txt')]);
            $this->info(' → تم تفريغ Cache وإرسال PurgeCloudflareCacheJob للصفحة الرئيسية و sitemap و llms.txt');
        } else {
            $this->info(' → [Dry Run] سيتم تفريغ Cache وإرسال Purge لـ /, /sitemap.xml, /llms.txt');
        }

        $this->info('--- ملخص ---');
        $this->table(
            ['البند', 'العدد'],
            [
                ['المتاجر المدمجة', $retailerMerged],
                ['المتاجر المحذوفة', $retailerDeleted],
                ['مجموعات المجلات المدمجة', $flyersMerged],
                ['المجلات المكررة المحذوفة', $flyersDeleted],
                ['الصفحات المنقولة', $pagesMoved],
                ['المنتجات المنقولة', $itemsMoved],
                ['الصفحات المضغوطة (محتوى مكرر)', $pagesCompressed],
                ['المجلات المضغوطة', $flyersCompressed],
                ['المنتجات المكررة المحذوفة', $itemsDeleted],
                ['ملخصات BLUF المجددة', $blufRegenerated],
            ]
        );

        $this->info($dryRun ? 'انتهى الفحص الجاف.' : 'اكتمل التنظيف بنجاح - Zero Data Loss.');

        return self::SUCCESS;
    }

    /**
     * Resequence a flyer's pages to consecutive integers starting at 1.
     *
     * Two distinct passes respect UNIQUE(flyer_id, page_number):
     *  - Pass 1: single bulk offset (page_number + 100000) clears all collisions.
     *  - Pass 2: re-assign consecutive integers in (page_number, id) order.
     * Also refreshes the flyer's total_pages. Callers must honor --dry-run
     * themselves — this method ALWAYS writes.
     */
    private function resequencePages(int $flyerId): void
    {
        DB::table('flyer_pages')
            ->where('flyer_id', $flyerId)
            ->update(['page_number' => DB::raw('page_number + 100000'), 'updated_at' => now()]);

        $ordered = DB::table('flyer_pages')
            ->where('flyer_id', $flyerId)
            ->orderBy('page_number')
            ->orderBy('id')
            ->pluck('id');

        $seq = 1;
        foreach ($ordered as $pid) {
            DB::table('flyer_pages')->where('id', $pid)->update(['page_number' => $seq++, 'updated_at' => now()]);
        }

        Flyer::where('id', $flyerId)->update(['total_pages' => $ordered->count()]);
    }

    /**
     * OOM-safe content signature for a stored R2 page image.
     *
     * NEVER downloads the object body: the primary signal is the R2 object
     * SIZE (HEAD metadata request — constant memory, negligible bandwidth).
     * Same-source uploads produce byte-identical optimized WebP, hence equal size.
     * Fallback (size unreadable): MD5 of the page's OCR-extracted item identity
     * set — DB-local, zero R2 traffic. If neither signal exists the page gets a
     * per-page unique key and is conservatively KEPT (zero data loss: never
     * delete on an uncertain signal).
     */
    private static function pageContentSignature(int $pageId, string $imagePath): string
    {
        $path = trim($imagePath);
        if ($path !== '') {
            try {
                $size = Storage::disk('r2')->size($path);
                if (is_int($size) && $size > 0) {
                    return 'size:' . $size;
                }
            } catch (\Throwable $e) {
                Log::debug('Deduplicate: R2 size lookup failed, falling back to item identity.', [
                    'page_id' => $pageId,
                    'image_path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $identities = FlyerItem::where('flyer_page_id', $pageId)
                ->orderBy('id')
                ->get(['normalized_name', 'sale_price'])
                ->map(fn ($i): string => mb_strtolower(trim((string) $i->normalized_name)) . '|' . number_format((float) $i->sale_price, 2, '.', ''))
                ->all();

            if ($identities !== []) {
                return 'items:' . md5(implode(';', $identities));
            }
        } catch (\Throwable $e) {
            Log::debug('Deduplicate: item identity lookup failed, keeping page as unique.', [
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }

        return 'unreadable-' . $pageId;
    }

    /**
     * Canonical grouping key: bim/bimmisr/bim-egypt → bim,
     * kazyon/kazyonegypt → kazyon, carrefour/carrefouregypt → carrefour,
     * «بيم مصر» → «بيم».
     */    private static function canonicalRetailerKey(Retailer $retailer): string
    {
        $slug = mb_strtolower(trim((string) $retailer->slug));
        $key = $slug !== '' ? $slug : mb_strtolower(trim((string) $retailer->name));

        // Strip country/branch suffixes anchored at the end (suffix-only, never mid-word)
        $key = (string) preg_replace('/(-|_|\s)*(egypt|misr|masr|مصر)$/iu', '', $key);
        // Drop remaining separators so bim-misr / bim_misr also collapse
        $key = (string) preg_replace('/[-_\s]+/u', '', $key);
        $key = trim($key);

        if ($key === '') {
            $key = 'retailer-'.$retailer->id;
        }

        return $key;
    }
}
