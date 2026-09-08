<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\View\View;

final class RetailerController extends Controller
{
    public function show(Retailer $retailer): View
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

        // SEO metadata: canonical = /{retailer:slug}, targeted Arabic Title
        $year = Carbon::now('Africa/Cairo')->year;
        $seoTitle = "عروض {$retailer->name} مصر اليوم {$year} | أحدث مجلات الأسعار والتخفيضات";
        $seoDescription = "تصفح أحدث عروض {$retailer->name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";
        $canonical = route('retailers.show', $retailer->slug);

        return view('retailers.show', [
            'retailer' => $retailer,
            'activeFlyers' => $activeFlyers,
            'expiredFlyers' => $expiredFlyers,
            'activeCount' => $activeCount,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonical' => $canonical,
        ]);
    }
}
