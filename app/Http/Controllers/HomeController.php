<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Support\ArabicNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Whole-word boundary MATCH + grocery-first RANK (SQLite-native):
     *
     *  rank 1  normalized_name = ?            (exact)
     *  rank 2  normalized_name LIKE 'X %'     (starts with the word)
     *  rank 3  normalized_name LIKE '% X %'   (whole word inside — matches
     *          "الممتاز زيت خليط", NEVER "زيتون": the space-padded word
     *          boundary refuses substring hits)
     *  rank 4  normalized_name LIKE '% X'     (ends with the word)
     *
     * Grocery/food/household categories rank ABOVE personal_care
     * ("بديل زيت" hair product) and automotive_tools ("ليكوي مولي" motor
     * oil) sinks to the very bottom.
     */
    private const WORD_RANK_SQL = <<<'SQL'
        CASE
            WHEN normalized_name = ? THEN 1
            WHEN normalized_name LIKE ? ESCAPE '\' THEN 2
            WHEN normalized_name LIKE ? ESCAPE '\' THEN 3
            WHEN normalized_name LIKE ? ESCAPE '\' THEN 4
            ELSE 5
        END
    SQL;

    private const CATEGORY_RANK_SQL = <<<'SQL'
        CASE
            WHEN category IN ('oils_ghee','groceries','dairy_cheese','meat_poultry','beverages_snacks','cleaning_laundry') THEN 1
            WHEN category IS NULL OR category = 'other' THEN 2
            WHEN category = 'home_appliances' THEN 3
            WHEN category = 'personal_care' THEN 4
            WHEN category = 'automotive_tools' THEN 5
            ELSE 2
        END
    SQL;

    /**
     * Explicit automotive intent terms — a query containing one of these is
     * SHOPPING for car products, so the automotive_tools exclusion is lifted.
     */
    private const AUTO_TERMS = [
        'زيت محرك', 'موتور', 'ليكوي', '5w', '10w', '15w', '20w',
        'سيارات', 'بطاري', 'مساحات', 'دريل', 'شنيور',
    ];

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
        $normalizedSearch = '';
        $escapedRaw = '';
        $escapedNormalized = '';
        $isWordQuery = false;
        $isAutoQuery = false;

        if ($searchQuery !== '') {
            $normalizedSearch = trim(ArabicNormalizer::normalize($searchQuery));
            $escapedRaw = self::likeWildcards($searchQuery);
            $escapedNormalized = self::likeWildcards($normalizedSearch);

            // Single WORD token → whole-word boundary matching everywhere.
            // Multi-token / symbol-only queries keep the legacy substring path.
            $tokens = preg_split('/\s+/u', $normalizedSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $isWordQuery = count($tokens) === 1 && preg_match('/^[\p{L}\p{N}]+$/u', $tokens[0]) === 1;
            $isAutoQuery = self::isAutoQuery(mb_strtolower($normalizedSearch));

            if ($isWordQuery) {
                $word = $tokens[0];
                $flyersQuery->where(function ($q) use ($escapedRaw, $word): void {
                    if ($escapedRaw !== '') {
                        $q->whereRaw("title LIKE ? ESCAPE '\\'", ["%{$escapedRaw}%"]);
                    }

                    $q->orWhereHas('items', fn ($subQ) => $subQ->where(function ($w) use ($word): void {
                        $w->whereRaw('normalized_name = ?', [$word])
                            ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", [$word.' %'])
                            ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", ['% '.$word.' %'])
                            ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", ['% '.$word]);
                    }));
                });
            } else {
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
        }

        $flyers = $flyersQuery->paginate(12)->withQueryString();

        // 3. Matching products for search (normalized) vs hot items for homepage
        $matchingItems = null;
        $hotItems = collect();

        $normalizedQuery = $searchQuery !== '' ? trim(ArabicNormalizer::normalize($searchQuery)) : '';

        if ($normalizedQuery !== '') {
            $matchingItems = $this->searchMatchingItems($normalizedQuery, $cairoToday, $isWordQuery, $isAutoQuery);

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

    /**
     * Does the query explicitly TARGET automotive/car products? A grocery
     * keyword search ("زيت") must never surface motor oil — a query that
     * names car parts ("زيت محرك") is allowed to.
     */
    private static function isAutoQuery(string $normalizedLower): bool
    {
        foreach (self::AUTO_TERMS as $term) {
            if (str_contains($normalizedLower, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ranked + deduplicated item search.
     *
     * Single-word queries use whole-word boundary matching with relevance
     * ranking (exact → starts-with → contains → ends-with, then grocery
     * categories above personal_care above automotive) with a SUBSTRING
     * fallback ONLY when the whole-word search returns ZERO rows.
     * Multi-token / symbol queries keep the classic escaped-substring path.
     *
     * Both paths deduplicate identical (flyer_id, normalized_name,
     * sale_price) rows — OCR double-reads of the same offer must never
     * occupy two slots of the 12-per-page result grid.
     */
    private function searchMatchingItems(string $normalizedQuery, string $cairoToday, bool $isWordQuery, bool $isAutoQuery): LengthAwarePaginator
    {
        $escaped = self::likeWildcards($normalizedQuery);

        $result = $isWordQuery
            ? $this->wordItemSearch($normalizedQuery, $cairoToday, $isAutoQuery)
            : null;

        // Whole-word search found NOTHING → graceful substring fallback
        // (typos inside compound names, latin brand fragments, etc.).
        if ($result === null || $result->total() === 0) {
            $result = $this->substringItemSearch($escaped, $cairoToday, $isAutoQuery);
        }

        return $result;
    }

    /**
     * Active-flyer constraint set shared by every matching-items query.
     */
    private function itemBaseQuery(string $cairoToday): Builder
    {
        return FlyerItem::query()
            ->where('sale_price', '>', 0)
            ->whereHas('flyer', function ($q) use ($cairoToday): void {
                $q->where('status', FlyerStatus::Published)
                    ->where('valid_until', '>=', $cairoToday)
                    ->whereHas('retailer', fn ($r) => $r->where('is_active', true));
            });
    }

    /**
     * Default grocery keyword search excludes motor oil/car items — unless
     * the query itself targets automotive terms. Rows whose category is
     * still NULL (legacy pre-Phase-1 data) are never excluded.
     */
    private function applyAutoExclusion(Builder $query, bool $isAutoQuery): Builder
    {
        if ($isAutoQuery) {
            return $query;
        }

        return $query->where(function ($w): void {
            $w->whereNull('category')->orWhere('category', '!=', 'automotive_tools');
        });
    }

    private function wordItemSearch(string $word, string $cairoToday, bool $isAutoQuery): LengthAwarePaginator
    {
        $matchPatterns = static fn (Builder $q): Builder => $q->where(function ($w) use ($word): void {
            $w->whereRaw('normalized_name = ?', [$word])
                ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", [$word.' %'])
                ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", ['% '.$word.' %'])
                ->orWhereRaw("normalized_name LIKE ? ESCAPE '\\'", ['% '.$word]);
        });

        // Dedup pass: identical (flyer_id, normalized_name, sale_price) rows
        // collapse onto their highest id.
        $dedupIds = $this->applyAutoExclusion(
            $this->itemBaseQuery($cairoToday),
            $isAutoQuery,
        )->where($matchPatterns)
            ->selectRaw('MAX(id) as aggregate_id')
            ->groupBy('flyer_id', 'normalized_name', 'sale_price')
            ->pluck('aggregate_id');

        return $this->applyAutoExclusion(
            FlyerItem::query()->with('flyer.retailer')->whereIn('id', $dedupIds),
            $isAutoQuery,
        )
            ->orderByRaw(self::WORD_RANK_SQL, [$word, $word.' %', '% '.$word.' %', '% '.$word])
            ->orderByRaw(self::CATEGORY_RANK_SQL)
            ->orderByDesc('id')
            ->paginate(12, ['*'], 'items_page')
            ->withQueryString();
    }

    private function substringItemSearch(string $escaped, string $cairoToday, bool $isAutoQuery): LengthAwarePaginator
    {
        $matchPatterns = static fn (Builder $q): Builder => $q
            ->whereRaw("normalized_name LIKE ? ESCAPE '\\'", ["%{$escaped}%"]);

        $dedupIds = $this->applyAutoExclusion(
            $this->itemBaseQuery($cairoToday),
            $isAutoQuery,
        )->where($matchPatterns)
            ->selectRaw('MAX(id) as aggregate_id')
            ->groupBy('flyer_id', 'normalized_name', 'sale_price')
            ->pluck('aggregate_id');

        return $this->applyAutoExclusion(
            FlyerItem::query()->with('flyer.retailer')->whereIn('id', $dedupIds),
            $isAutoQuery,
        )
            ->orderByRaw(self::CATEGORY_RANK_SQL)
            ->orderByDesc('id')
            ->paginate(12, ['*'], 'items_page')
            ->withQueryString();
    }
}
