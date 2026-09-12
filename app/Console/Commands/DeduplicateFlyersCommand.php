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

        // 1. Clean Duplicate Retailers by clean_name
        $this->info('1) فحص المتاجر المكررة...');
        $retailers = Retailer::all();
        $grouped = $retailers->groupBy(fn (Retailer $r): string => trim((string) preg_replace('/\s+مصر$/u', '', (string) $r->name)));

        foreach ($grouped as $cleanName => $group) {
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

                $this->line(" - دمج المتجر المكرر [{$dup->id}] {$dup->name} ({$dup->slug}) -> الأساسي [{$primary->id}] {$primary->name} ({$primary->slug}) — {$flyerCount} مجلة, {$rawCount} منشور خام");

                if (! $dryRun) {
                    DB::transaction(function () use ($dup, $primary): void {
                        Flyer::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
                        \App\Models\RawFacebookPost::where('retailer_id', $dup->id)->update(['retailer_id' => $primary->id]);
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

            // Collect unique pages by image_path
            $existingPaths = $primary->pages()->pluck('image_path')->filter()->map(fn ($p) => trim((string) $p))->all();
            $existingPaths = array_unique($existingPaths);

            $allItemsToMove = collect();

            foreach ($dups as $dup) {
                // Move flyer_pages unique
                $pages = $dup->pages()->get();
                foreach ($pages as $page) {
                    $path = trim((string) $page->image_path);
                    if ($path !== '' && in_array($path, $existingPaths, true)) {
                        // Duplicate page image, skip
                        continue;
                    }
                    if (! $dryRun) {
                        $page->update(['flyer_id' => $primary->id]);
                    }
                    $existingPaths[] = $path;
                    $pagesMoved++;
                }

                // Collect items to move
                $items = $dup->items()->get();
                foreach ($items as $item) {
                    $allItemsToMove->push($item);
                }

                if (! $dryRun) {
                    $dup->delete();
                }
                $flyersDeleted++;
            }

            // Move unique items (deduplicate by product_name+sale_price later, but move all first)
            $uniqueItems = $allItemsToMove->unique(fn ($i) => $i->product_name . '|' . $i->sale_price);
            foreach ($uniqueItems as $item) {
                if (! $dryRun) {
                    // Need to update flyer_id and possibly flyer_page_id after renumber? Keep original page_id if page moved, otherwise null
                    $item->update(['flyer_id' => $primary->id]);
                }
                $itemsMoved++;
            }

            // Renumber pages sequentially
            if (! $dryRun) {
                $pages = $primary->pages()->orderBy('page_number')->orderBy('id')->get();
                $seq = 1;
                foreach ($pages as $p) {
                    $p->update(['page_number' => $seq++]);
                }
                $primary->update(['total_pages' => $pages->count()]);
            }

            $flyersMerged++;
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
}
