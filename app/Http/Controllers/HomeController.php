<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Support\ArabicNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class HomeController extends Controller
{
    /**
     * Escapes SQLite LIKE wildcards so user input can never wildcard-scan
     * the whole items table (DoS guard on a 2-core box).
     */
    private static function likeWildcards(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function __invoke(Request $request): View|Response
    {
        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
        $selectedRetailerSlug = $request->string('retailer')->toString();
        // Cap search input: unbounded strings → giant LIKE patterns → full scans.
        $searchQuery = mb_substr($request->string('q')->trim()->toString(), 0, 100);

        $retailers = Retailer::where('is_active', true)->whereNull('parent_id')->orderBy('name')->get()->unique('name')->values();

        // 1. استعلام المجلات السارية فقط — coverPage + pages_count بدل تحميل كل الصفحات
        //    (O(12) rows hydration instead of O(12 × N pages) on an 840MB box).
        $flyersQuery = Flyer::with(['retailer', 'coverPage'])
            ->withCount('pages')
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->whereHas('retailer', fn ($q) => $q->where('is_active', true))
            ->latest('valid_from');

        if (! empty($selectedRetailerSlug)) {
            $flyersQuery->whereHas('retailer', fn ($q) => $q->where('slug', $selectedRetailerSlug));
        }

        // 2. البحث النصي الذكي باستخدام الحقل المطهر (Arabic Normalization)
        //    Wildcards are escaped + bound via whereRaw ... ESCAPE '\' so that
        //    ?q=% or ?q=_ match literals instead of scanning every row.
        if ($searchQuery !== '') {
            $normalizedSearch = trim(ArabicNormalizer::normalize($searchQuery));
            $escapedRaw = self::likeWildcards($searchQuery);
            $escapedNormalized = self::likeWildcards($normalizedSearch);

            $flyersQuery->where(function ($q) use ($escapedRaw, $escapedNormalized, $normalizedSearch): void {
                if ($escapedRaw !== '') {
                    $q->whereRaw("title LIKE ? ESCAPE '\\'", ["%{$escapedRaw}%"]);
                }

                if ($normalizedSearch !== '') {
                    $q->orWhereHas('items', fn ($subQ) => $subQ
                        ->whereRaw("normalized_name LIKE ? ESCAPE '\\'", ["%{$escapedNormalized}%"]));
                }

                if ($escapedRaw === '' && $normalizedSearch === '') {
                    // Search string normalizes to nothing (e.g. only symbols):
                    // match nothing instead of everything.
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $flyers = $flyersQuery->paginate(12)->withQueryString();

        // 3. Matching products for search (normalized) vs hot items for homepage
        $matchingItems = null;
        $hotItems = collect();

        $normalizedQuery = $searchQuery !== '' ? trim(ArabicNormalizer::normalize($searchQuery)) : '';

        if ($normalizedQuery !== '') {
            $escapedNormalizedQuery = self::likeWildcards($normalizedQuery);

            $matchingItems = FlyerItem::with('flyer.retailer')
                ->whereRaw("normalized_name LIKE ? ESCAPE '\\'", ["%{$escapedNormalizedQuery}%"])
                ->where('sale_price', '>', 0)
                ->whereHas('flyer', function ($q) use ($cairoToday): void {
                    $q->where('status', FlyerStatus::Published)
                        ->where('valid_until', '>=', $cairoToday)
                        ->whereHas('retailer', fn ($r) => $r->where('is_active', true));
                })
                ->latest('id')
                ->paginate(12, ['*'], 'items_page')
                ->withQueryString();

            // When search is active, hide generic hotItems and only display matchingItems
            $hotItems = collect();
        } else {
            // 3b. أقوى السلع المخفضة اليوم (أكبر نسبة توفير) - فقط عند عدم وجود بحث
            $hotItems = FlyerItem::with('flyer.retailer')
                ->whereHas('flyer', function ($q) use ($cairoToday): void {
                    $q->where('status', FlyerStatus::Published)
                        ->where('valid_until', '>=', $cairoToday)
                        ->whereHas('retailer', fn ($r) => $r->where('is_active', true));
                })
                ->where('sale_price', '>', 0)
                ->whereNotNull('discount_percent')
                ->where('discount_percent', '>', 10)
                ->orderByDesc('discount_percent')
                ->limit(6)
                ->get();
        }

        $isFilteredSearch = $searchQuery !== '' || $selectedRetailerSlug !== '';

        $wantsMarkdown = $request->header('Accept') === 'text/markdown'
            || str_contains((string) $request->header('Accept'), 'text/markdown')
            || $request->query('_fmt') === 'md'
            || $request->query('format') === 'md';

        if ($wantsMarkdown) {
            $response = response()
                ->view('home-markdown', compact('retailers', 'flyers', 'hotItems', 'matchingItems', 'searchQuery'))
                ->header('Content-Type', 'text/markdown; charset=UTF-8')
                ->header('Vary', 'Accept')
                ->header('X-Robots-Tag', 'noindex, follow')
                ->header('X-Content-Type-Options', 'nosniff');
        } else {
            $response = response()
                ->view('home', compact('retailers', 'flyers', 'hotItems', 'matchingItems', 'searchQuery'))
                ->header('Vary', 'Accept');
        }

        // Search/filter result pages are infinite URL spaces: never index them.
        // Type-filtered views and pagination (?page=2+) duplicate page-1
        // content as well — keep them out of the index too.
        if ($isFilteredSearch || $request->filled('type') || (int) $request->query('page', 1) > 1) {
            $response->header('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
