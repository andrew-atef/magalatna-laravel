<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Support\R2Url;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class RetailerController extends Controller
{
    public function show(Request $request, Retailer $retailer): View|Response
    {
        abort_unless($retailer->is_active, 404);

        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
        $thirtyDaysAgo = Carbon::today('Africa/Cairo')->subDays(30)->toDateString();

        // Active published flyers for this retailer with valid_until >= Cairo today.
        // coverPage + pages_count (NOT the full pages relation) — the hub only
        // renders thumbnails; hydrating every page row wasted MBs of RAM.
        $activeFlyers = $retailer->flyers()
            ->with(['coverPage'])
            ->withCount('pages')
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->latest('id')
            ->paginate(12, ['*'], 'page')
            ->withQueryString();

        // EVERGREEN HUB accuracy split — both boundaries evaluated in
        // Africa/Cairo so a flyer printed "today" can NEVER be misfiled as
        // upcoming (or vice versa):
        //  - activeTodayCount: valid_from <= today <= valid_until ("سارية الآن")
        //  - upcomingCount: published BEFORE their start date ("تبدأ قريباً")
        // whereDate is REQUIRED for valid_from: the date cast persists
        // DATETIME strings ('2026-11-17 00:00:00') but $cairoToday is a bare
        // 'Y-m-d' — a raw string compare on SQLite reads "today 00:00:00"
        // as GREATER than today (misclassifying every live-today flyer as
        // upcoming). MySQL coerces, SQLite does not.
        $activeTodayCount = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->whereDate('valid_from', '<=', $cairoToday)
            ->where('valid_until', '>=', $cairoToday)
            ->count();

        $upcomingCount = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->whereDate('valid_from', '>', $cairoToday)
            ->where('valid_until', '>=', $cairoToday)
            ->count();

        $activeCount = $activeTodayCount;

        // Recently expired flyers (last 30 days) for price history tab/section.
        //  - Status=Expired: manually expired flyers may still have
        //    valid_until >= today — include them when the window OR the last
        //    update falls inside the last 30 days.
        //  - Status=Published: naturally lapsed flyers (valid_until < today).
        // Capped at 6 (was 12): archive cards are noindex-links and rows 7..12
        // were pure crawl waste on a page whose job is LIVE offers.
        $expiredFlyers = $retailer->flyers()
            ->with(['coverPage'])
            ->withCount('pages')
            ->where(function ($q) use ($cairoToday, $thirtyDaysAgo): void {
                $q->where(function ($q2) use ($thirtyDaysAgo): void {
                    $q2->where('status', FlyerStatus::Expired)
                        ->where(function ($q3) use ($thirtyDaysAgo): void {
                            $q3->where('valid_until', '>=', $thirtyDaysAgo)
                                ->orWhere('updated_at', '>=', now('Africa/Cairo')->subDays(30));
                        });
                })->orWhere(function ($q2) use ($cairoToday, $thirtyDaysAgo): void {
                    $q2->where('status', FlyerStatus::Published)
                        ->where('valid_until', '<', $cairoToday)
                        ->where('valid_until', '>=', $thirtyDaysAgo);
                });
            })
            ->latest('valid_until')
            ->limit(6)
            ->get();

        // GEO / AI-crawler item-level price highlights — DIVERSIFIED: up to 4
        // flyers contribute their top-4 discounted items EACH (max 16 rows
        // total), then a round-robin across categories keeps the table from
        // being 16 cooking-oil rows. Every item carries its OWN hydrated
        // flyer (id/slug/title/valid_until) so deep links and the
        // "المجلة / العرض" column are always accurate. Skipped entirely when
        // no flyer is live today (gate runs BEFORE any query executes).
        $topRetailerItems = collect();
        if ($activeTodayCount > 0) {
            // Eligible flyers: published + not yet expired, ACTIVE-TODAY
            // flyers FIRST (CASE bucket), longest validity next — so the
            // expiring-tomorrow weekly and the fresh weekly both feed the
            // table instead of only the newest-started one. date() on the
            // raw CASE too: bare 'Y-m-d' vs DATETIME strings misranks
            // same-day flyers on SQLite (see activeTodayCount above).
            $eligibleFlyerIds = $retailer->flyers()
                ->where('status', FlyerStatus::Published)
                ->where('valid_until', '>=', $cairoToday)
                ->orderByRaw('CASE WHEN date(valid_from) <= ? THEN 0 ELSE 1 END ASC, valid_until DESC', [$cairoToday])
                ->pluck('id');

            $merged = collect();

            foreach ($eligibleFlyerIds->take(4) as $dealFlyerId) {
                // A flyer that has not STARTED yet must never feed a table
                // titled "السارية اليوم" — skip it (it sorts last anyway).
                $dealFlyer = Flyer::query()
                    ->where('id', $dealFlyerId)
                    ->whereDate('valid_from', '<=', $cairoToday)
                    ->first(['id', 'slug', 'title', 'valid_from', 'valid_until', 'discover_image_path']);

                if ($dealFlyer === null) {
                    continue;
                }

                $items = FlyerItem::query()
                    ->where('flyer_id', $dealFlyer->id)
                    ->where('sale_price', '>', 0)
                    // Items with no computed discount sort last (never "0%").
                    ->orderByRaw('CASE WHEN discount_percent IS NULL THEN 1 ELSE 0 END, discount_percent DESC')
                    ->limit(4)
                    ->get(['id', 'flyer_id', 'brand_id', 'product_name', 'sale_price', 'old_price', 'discount_percent', 'unit', 'category']);

                // ONE hydrated flyer object per item — no N+1 in the view.
                $items->each(fn (FlyerItem $item) => $item->setRelation('flyer', $dealFlyer));

                $merged = $merged->merge($items);
            }

            $topRetailerItems = self::diversifyByCategory($merged, 16);
        }

        // og:image: the 1280x720 Google Discover composite of the ACTIVE
        // (already live) flyer with the LONGEST remaining validity wins
        // (native 16:9 canvas — the weekly that stays live the longest is
        // the evergreen hub face), else its cover master, else the retailer
        // logo, else the site fallback. A flyer that has not STARTED yet
        // must never front a "عروض اليوم" hub. $ogImageIsDiscover lets the
        // view declare the exact 1280x720 og:image dimensions.
        $bestFlyer = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->whereDate('valid_from', '<=', $cairoToday)
            ->orderByDesc('valid_until')
            ->orderByDesc('id')
            ->first();

        $ogImage = $bestFlyer?->discover_image_path
            ? R2Url::asset($bestFlyer->discover_image_path)
            : ($bestFlyer?->coverPage?->image_path
                ? R2Url::asset($bestFlyer->coverPage->image_path)
                : ($retailer->logo_path ? R2Url::asset($retailer->logo_path) : url('/img/og-cover.png')));
        $ogImageIsDiscover = (bool) $bestFlyer?->discover_image_path;

        // SEO metadata: canonical = /{retailer:slug}; targeted Arabic title
        // kept inside Google's ~56-char pixel-safe window (the layout appends
        // the " | مجلاتنا" site suffix itself). hub_name strips English
        // parentheticals "(Kazyon)" — pure noise in Arabic SERPs.
        $year = Carbon::now('Africa/Cairo')->year;
        $hubName = $retailer->hub_name;
        $siteSuffix = ' | '.(string) config('app.name', 'مجلاتنا');
        $titleBudget = 56;
        $titleCandidates = [
            "عروض {$hubName} اليوم في مصر {$year} | أحدث المجلات والأسعار",
            "عروض {$hubName} اليوم في مصر {$year} | مجلات وأسعار",
            "عروض {$hubName} اليوم في مصر {$year}",
        ];
        $seoTitle = $titleCandidates[0];
        $fullLength = static fn (string $t): int => mb_strlen($t) + mb_strlen($siteSuffix);
        foreach ($titleCandidates as $titleCandidate) {
            if ($fullLength($titleCandidate) <= $titleBudget) {
                $seoTitle = $titleCandidate;
                break;
            }
        }
        if ($fullLength($seoTitle) > $titleBudget) {
            // Word-safe cut at the last space — NO trailing ellipsis.
            $cut = mb_substr($seoTitle, 0, $titleBudget - mb_strlen($siteSuffix));
            $space = mb_strrpos($cut, ' ');
            $seoTitle = $space !== false && $space > 20 ? mb_substr($cut, 0, $space) : $cut;
        }
        $seoDescription = "تصفح أحدث عروض {$retailer->clean_name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";

        // Canonical host from config ONLY — request host (admin panel, CLI,
        // preview hosts) must never leak into SEO tags.
        $canonical = rtrim((string) config('app.url'), '/')
            .route('retailers.show', ['retailer' => $retailer->slug], false);

        $otherActiveFlyers = Flyer::query()
            ->where('status', FlyerStatus::Published)
            ->where('retailer_id', '!=', $retailer->id)
            ->where('valid_until', '>=', Carbon::today('Africa/Cairo')->toDateString())
            ->whereHas('retailer', fn ($q) => $q->where('is_active', true))
            ->with(['retailer', 'coverPage'])
            ->latest('valid_from')
            ->take(4)
            ->get();

        $viewData = [
            'retailer' => $retailer,
            'activeFlyers' => $activeFlyers,
            'upcomingCount' => $upcomingCount,
            'expiredFlyers' => $expiredFlyers,
            'otherActiveFlyers' => $otherActiveFlyers,
            'activeCount' => $activeCount,
            'activeTodayCount' => $activeTodayCount,
            'topRetailerItems' => $topRetailerItems,
            'ogImage' => $ogImage,
            'ogImageIsDiscover' => $ogImageIsDiscover,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonical' => $canonical,
        ];

        $wantsMarkdown = $request->header('Accept') === 'text/markdown'
            || str_contains((string) $request->header('Accept'), 'text/markdown')
            || $request->query('_fmt') === 'md'
            || $request->query('format') === 'md';

        if ($wantsMarkdown) {
            return response()
                ->view('retailers.show-markdown', $viewData)
                ->header('Content-Type', 'text/markdown; charset=UTF-8')
                ->header('Vary', 'Accept')
                ->header('X-Robots-Tag', 'noindex, follow')
                ->header('Link', "<{$canonical}>; rel=\"canonical\"")
                ->header('X-Content-Type-Options', 'nosniff');
        }

        $response = response()
            ->view('retailers.show', $viewData)
            ->header('Vary', 'Accept');

        // Paginated hub pages point their canonical at page 1 — keep ?page=2+
        // out of the index (follow is fine: the links still pass equity).
        if ((int) $request->query('page', 1) > 1) {
            $response->header('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    /**
     * Round-robin across categories over a discount-sorted pool: within each
     * category the top-discount order is preserved, but consecutive picks
     * rotate categories so the table reads as a real basket, not 15 oils.
     *
     * @param  Collection<int, FlyerItem>  $items
     * @return Collection<int, FlyerItem>
     */
    private static function diversifyByCategory(Collection $items, int $max): Collection
    {
        if ($items->count() <= $max) {
            return $items->values();
        }

        $groups = $items
            ->groupBy(static fn (FlyerItem $item): string => trim((string) $item->category) !== '' ? (string) $item->category : 'other')
            ->values()
            ->map(static fn ($group) => $group->values());

        $diversified = collect();

        while ($diversified->count() < $max && $groups->contains(static fn ($group) => $group->isNotEmpty())) {
            foreach ($groups as $group) {
                if ($group->isNotEmpty()) {
                    $diversified->push($group->shift());

                    if ($diversified->count() >= $max) {
                        break;
                    }
                }
            }
        }

        return $diversified->values();
    }
}
