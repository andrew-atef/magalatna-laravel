<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Flyer;
use App\Models\Retailer;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class ProvideMarkdownForAiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $wantsMarkdown = str_contains((string) $request->header('Accept', ''), 'text/markdown')
            || $request->query('format') === 'md'
            || $request->query('_fmt') === 'md';

        if (! $wantsMarkdown) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // Flyer Show: /offers/{slug}
        if ($routeName === 'flyers.show') {
            $slug = (string) $request->route('slug');
            $flyer = Flyer::with(['retailer', 'items.brand', 'pages'])
                ->where('slug', $slug)
                ->first();

            if ($flyer === null) {
                return $next($request);
            }

            $markdown = View::make('flyers.show-markdown', compact('flyer'))->render();

            return response($markdown, 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Vary' => 'Accept',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Retailer Hub: /{retailer:slug}
        if ($routeName === 'retailers.show') {
            $retailerParam = $request->route('retailer');
            $retailer = null;
            if ($retailerParam instanceof Retailer) {
                $retailer = $retailerParam;
            } elseif (is_string($retailerParam)) {
                $retailer = Retailer::where('slug', $retailerParam)->first();
            } else {
                $slug = (string) $request->route('retailer');
                $retailer = Retailer::where('slug', $slug)->first();
            }

            if ($retailer === null || ! $retailer->is_active) {
                return $next($request);
            }

            $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
            $thirtyDaysAgo = Carbon::today('Africa/Cairo')->subDays(30)->toDateString();

            $activeFlyers = $retailer->flyers()
                ->with(['pages', 'items'])
                ->where('status', 'published')
                ->where('valid_until', '>=', $cairoToday)
                ->latest('valid_from')
                ->latest('id')
                ->paginate(12, ['*'], 'page')
                ->withQueryString();

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

            $activeCount = $retailer->flyers()
                ->where('status', 'published')
                ->where('valid_from', '<=', $cairoToday)
                ->where('valid_until', '>=', $cairoToday)
                ->count();

            $year = Carbon::now('Africa/Cairo')->year;
            $seoTitle = "عروض {$retailer->name} مصر اليوم {$year} | أحدث مجلات الأسعار والتخفيضات";
            $seoDescription = "تصفح أحدث عروض {$retailer->name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";
            $canonical = route('retailers.show', $retailer->slug);

            $markdown = View::make('retailers.show-markdown', compact('retailer', 'activeFlyers', 'expiredFlyers', 'activeCount', 'seoTitle', 'seoDescription', 'canonical'))->render();

            return response($markdown, 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Vary' => 'Accept',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Homepage: /
        if ($routeName === 'home') {
            $cairoToday = Carbon::today('Africa/Cairo')->toDateString();
            $retailers = Retailer::where('is_active', true)->orderBy('name')->get();

            $flyers = \App\Models\Flyer::with(['retailer', 'pages'])
                ->where('status', 'published')
                ->where('valid_until', '>=', $cairoToday)
                ->latest('valid_from')
                ->paginate(12);

            $hotItems = \App\Models\FlyerItem::with('flyer.retailer')
                ->whereHas('flyer', function ($q) use ($cairoToday) {
                    $q->where('status', 'published')->where('valid_until', '>=', $cairoToday);
                })
                ->whereNotNull('discount_percent')
                ->where('discount_percent', '>', 10)
                ->orderByDesc('discount_percent')
                ->limit(6)
                ->get();

            $markdown = View::make('home-markdown', compact('retailers', 'flyers', 'hotItems'))->render();

            return response($markdown, 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Vary' => 'Accept',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $next($request);
    }
}
