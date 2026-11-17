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

        // EVERGREEN HUB accuracy split:
        //  - activeTodayFlyers: valid_from <= today <= valid_until ("سارية الآن")
        //  - upcomingFlyers: published BEFORE their start date ("تبدأ قريباً")
        // A flyer starting tomorrow must never be counted as live today.
        $activeTodayFlyers = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->where('valid_from', '<=', $cairoToday)
            ->where('valid_until', '>=', $cairoToday)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get(['id', 'slug', 'title', 'valid_from', 'valid_until', 'discover_image_path']);

        $upcomingFlyers = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->where('valid_from', '>', $cairoToday)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get(['id', 'slug', 'title', 'valid_from', 'valid_until']);

        $activeCount = $activeTodayFlyers->count();
        $upcomingCount = $upcomingFlyers->count();

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

        // GEO / AI-crawler item-level price highlights — DIVERSIFIED: up to 3
        // live-today flyers contribute their top-4 discounted items EACH
        // (max 15 rows total), then a round-robin across categories keeps the
        // table from being 15 cooking-oil rows. Every item carries its OWN
        // hydrated flyer (id/slug/title/valid_until) so deep links and the
        // "المجلة / العرض" column are always accurate. Skipped entirely when
        // no flyer is live today (gate runs BEFORE any query executes).
        $topRetailerItems = collect();
        if ($activeCount > 0) {
            $merged = collect();

            foreach ($activeTodayFlyers->take(3) as $dealFlyer) {
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

            $topRetailerItems = self::diversifyByCategory($merged, 15);
        }

        // og:image: the 1280x720 Google Discover composite of the LATEST
        // active flyer wins (native 16:9 canvas), else its cover master,
        // else the retailer logo, else the site fallback.
        $ogImage = self::resolveHubOgImage($retailer, $activeTodayFlyers->first());

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
            'activeTodayFlyers' => $activeTodayFlyers,
            'upcomingFlyers' => $upcomingFlyers,
            'upcomingCount' => $upcomingCount,
            'expiredFlyers' => $expiredFlyers,
            'otherActiveFlyers' => $otherActiveFlyers,
            'activeCount' => $activeCount,
            'topRetailerItems' => $topRetailerItems,
            'ogImage' => $ogImage,
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

    /**
     * Hub og:image: the 1280x720 Discover composite of the latest active
     * flyer (exact 16:9 social canvas), else that flyer's cover master,
     * else the retailer logo, else the site fallback.
     */
    private static function resolveHubOgImage(Retailer $retailer, ?Flyer $latestFlyer): string
    {
        if ($latestFlyer !== null) {
            $discover = trim((string) ($latestFlyer->discover_image_path ?? ''));
            if ($discover !== '') {
                return R2Url::asset($discover);
            }

            $cover = $latestFlyer->coverPage()->first(['image_path']);
            if ($cover !== null && trim((string) $cover->image_path) !== '') {
                return R2Url::asset((string) $cover->image_path);
            }
        }

        return $retailer->logo_path
            ? R2Url::asset($retailer->logo_path)
            : url('/img/og-cover.png');
    }
}
