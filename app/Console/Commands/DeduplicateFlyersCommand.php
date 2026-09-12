<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

                // 4) Safe renumber sequentially (two-phase to avoid unique collisions)
                $pages = $primaryFresh->pages()->orderBy('page_number')->orderBy('id')->get();
                $offset = 1000000;
                $seq = 0;
                foreach ($pages as $p) {
                    // Use query builder to avoid model events overhead; direct update
                    DB::table('flyer_pages')->where('id', $p->id)->update(['page_number' => $offset + ($seq++), 'updated_at' => now()]);
                }
                $seq = 1;
                $ordered = DB::table('flyer_pages')->where('flyer_id', $primaryFresh->id)->orderBy('page_number')->orderBy('id')->pluck('id');
                foreach ($ordered as $pid) {
                    DB::table('flyer_pages')->where('id', $pid)->update(['page_number' => $seq++, 'updated_at' => now()]);
                }
                $primaryFresh->update(['total_pages' => $ordered->count()]);
                $flyersMerged++;
            });
        }
        $this->info(" → مجموعات مكررة: {$flyersMerged} مجموعة، حذف {$flyersDeleted} مجلة مكررة، نقل {$pagesMoved} صفحة و {$itemsMoved} منتج");

        // 3. Deduplicate FlyerItem Records within each flyer (same product_name + sale_price)
        $this->info('3) إزالة المنتجات المكررة داخل كل مجلة...');
        $flyersAll = Flyer::with('items')->get();
        foreach ($flyersAll as $flyer) {
            $seen = [];
            foreach ($flyer->items()->orderBy('id')->get() as $item) {
                $key = mb_strtolower(trim((string) $item->product_name)) . '|' . number_format((float) $item->sale_price, 2, '.', '');
                if (isset($seen[$key])) {
                    if (! $dryRun) {
                        $item->delete();
                    }
                    $itemsDeleted++;
                } else {
                    $seen[$key] = true;
                }
            }
        }
        $this->info(" → حذف {$itemsDeleted} منتج مكرر");

        // 4. Flush & Invalidate Caches
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
                ['المنتجات المكررة المحذوفة', $itemsDeleted],
            ]
        );

        $this->info($dryRun ? 'انتهى الفحص الجاف.' : 'اكتمل التنظيف بنجاح - Zero Data Loss.');

        return self::SUCCESS;
    }

    /**
     * Canonical grouping key: bim/bimmisr/bim-egypt → bim,
     * kazyon/kazyonegypt → kazyon, carrefour/carrefouregypt → carrefour,
     * «بيم مصر» → «بيم».
     */
    private static function canonicalRetailerKey(Retailer $retailer): string
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
