<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        // Recently expired flyers (last 30 days) for price history tab/section.
        //  - Status=Expired: manually expired flyers may still have
        //    valid_until >= today — include them when the window OR the last
        //    update falls inside the last 30 days.
        //  - Status=Published: naturally lapsed flyers (valid_until < today).
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
            ->limit(12)
            ->get();

        // Truly active now (valid_from <= today <= valid_until) for accurate "سارية الآن" count
        $activeCount = $retailer->flyers()
            ->where('status', FlyerStatus::Published)
            ->where('valid_from', '<=', $cairoToday)
            ->where('valid_until', '>=', $cairoToday)
            ->count();

        // SEO metadata: canonical = /{retailer:slug}, targeted Arabic Title (smart clean_name to avoid مصر مصر stuttering)
        $year = Carbon::now('Africa/Cairo')->year;
        $seoTitle = "عروض {$retailer->clean_name} في مصر اليوم {$year} | أحدث المجلات والتخفيضات";
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
            'expiredFlyers' => $expiredFlyers,
            'otherActiveFlyers' => $otherActiveFlyers,
            'activeCount' => $activeCount,
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
}
