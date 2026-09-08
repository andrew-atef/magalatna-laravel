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
    /**
     * @return View|Response
     */
    public function show(Request $request, Retailer $retailer): View|Response
    {
        abort_unless($retailer->is_active, 404);

        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
        $thirtyDaysAgo = Carbon::today('Africa/Cairo')->subDays(30)->toDateString();

        // Active published flyers for this retailer with valid_until >= Cairo today
        $activeFlyers = $retailer->flyers()
            ->with(['pages', 'items'])
            ->where('status', 'published')
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->latest('id')
            ->paginate(12, ['*'], 'page')
            ->withQueryString();

        // Recently expired flyers (last 30 days) for price history tab/section
        $expiredFlyers = $retailer->flyers()
            ->with(['pages', 'items'])
            ->where(function ($q) use ($cairoToday, $thirtyDaysAgo): void {
                $q->where(function ($q2) use ($thirtyDaysAgo): void {
                    $q2->where('status', 'expired')
                        ->whereBetween('valid_until', [$thirtyDaysAgo, Carbon::today('Africa/Cairo')->subDay()->toDateString()]);
                })->orWhere(function ($q2) use ($cairoToday, $thirtyDaysAgo): void {
                    $q2->where('status', 'published')
                        ->where('valid_until', '<', $cairoToday)
                        ->where('valid_until', '>=', $thirtyDaysAgo);
                });
            })
            ->latest('valid_until')
            ->limit(12)
            ->get();

        // Truly active now (valid_from <= today <= valid_until) for accurate "سارية الآن" count
        $activeCount = $retailer->flyers()
            ->where('status', 'published')
            ->where('valid_from', '<=', $cairoToday)
            ->where('valid_until', '>=', $cairoToday)
            ->count();

        // SEO metadata: canonical = /{retailer:slug}, targeted Arabic Title (smart clean_name to avoid مصر مصر stuttering)
        $year = Carbon::now('Africa/Cairo')->year;
        $seoTitle = "عروض {$retailer->clean_name} في مصر اليوم {$year} | أحدث المجلات والتخفيضات";
        $seoDescription = "تصفح أحدث عروض {$retailer->clean_name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";
        $canonical = route('retailers.show', $retailer->slug);

        $otherActiveFlyers = Flyer::where('status', FlyerStatus::Published)
            ->where('retailer_id', '!=', $retailer->id)
            ->whereDate('valid_until', '>=', now('Africa/Cairo')->toDateString())
            ->with('retailer')
            ->latest('valid_from')
            ->take(4)
            ->get();

        $wantsMarkdown = $request->header('Accept') === 'text/markdown'
            || str_contains((string) $request->header('Accept'), 'text/markdown')
            || $request->query('_fmt') === 'md';

        if ($wantsMarkdown) {
            return response()
                ->view('retailers.show-markdown', [
                    'retailer' => $retailer,
                    'activeFlyers' => $activeFlyers,
                    'expiredFlyers' => $expiredFlyers,
                    'otherActiveFlyers' => $otherActiveFlyers,
                    'activeCount' => $activeCount,
                    'seoTitle' => $seoTitle,
                    'seoDescription' => $seoDescription,
                    'canonical' => $canonical,
                ])
                ->header('Content-Type', 'text/markdown; charset=UTF-8')
                ->header('Vary', 'Accept')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return view('retailers.show', [
            'retailer' => $retailer,
            'activeFlyers' => $activeFlyers,
            'expiredFlyers' => $expiredFlyers,
            'otherActiveFlyers' => $otherActiveFlyers,
            'activeCount' => $activeCount,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonical' => $canonical,
        ]);
    }
}
