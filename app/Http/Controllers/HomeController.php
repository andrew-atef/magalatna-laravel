<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\Retailer;
use App\Support\ArabicNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
        $selectedRetailerSlug = $request->string('retailer')->toString();
        $searchQuery = $request->string('q')->trim()->toString();

        $retailers = Retailer::where('is_active', true)->orderBy('name')->get();

        // 1. استعلام المجلات السارية فقط
        $flyersQuery = Flyer::with(['retailer', 'pages'])
            ->where('status', 'published')
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from');

        if (! empty($selectedRetailerSlug)) {
            $flyersQuery->whereHas('retailer', fn ($q) => $q->where('slug', $selectedRetailerSlug));
        }

        // 2. البحث النصي الذكي باستخدام الحقل المطهر (Arabic Normalization)
        if (! empty($searchQuery)) {
            $normalizedSearch = ArabicNormalizer::normalize($searchQuery);

            $flyersQuery->where(function ($q) use ($searchQuery, $normalizedSearch) {
                $q->where('title', 'like', "%{$searchQuery}%")
                    ->orWhereHas('items', fn ($subQ) => $subQ->where('normalized_name', 'like', "%{$normalizedSearch}%"));
            });
        }

        $flyers = $flyersQuery->paginate(12)->withQueryString();

        // 3. أقوى السلع المخفضة اليوم (أكبر نسبة توفير)
        $hotItems = FlyerItem::with('flyer.retailer')
            ->whereHas('flyer', function ($q) use ($cairoToday) {
                $q->where('status', 'published')->where('valid_until', '>=', $cairoToday);
            })
            ->whereNotNull('discount_percent')
            ->where('discount_percent', '>', 10)
            ->orderByDesc('discount_percent')
            ->limit(6)
            ->get();

        return view('home', compact('retailers', 'flyers', 'hotItems'));
    }
}
