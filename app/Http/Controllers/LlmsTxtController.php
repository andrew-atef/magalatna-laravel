<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class LlmsTxtController extends Controller
{
    public function index(): Response
    {
        // Both Eloquent queries live INSIDE the closure: cache hits execute
        // ZERO database queries (6h TTL, invalidated by observers).
        $content = Cache::remember('llms_txt_content', 21600, function (): string {
            $todayCairo = now('Africa/Cairo')->toDateString();

            $retailers = Retailer::query()
                ->where('is_active', true)
                ->withCount(['flyers' => function ($q) use ($todayCairo): void {
                    $q->where('status', FlyerStatus::Published)
                        ->where('valid_until', '>=', $todayCairo);
                }])
                ->orderBy('name')
                ->get();

            $activeFlyers = Flyer::query()
                ->where('status', FlyerStatus::Published)
                ->where('valid_until', '>=', $todayCairo)
                ->whereHas('retailer', fn ($q) => $q->where('is_active', true))
                ->with('retailer')
                ->latest('valid_from')
                ->take(15)
                ->get();

            return view('llms', compact('retailers', 'activeFlyers'))->render();
        });

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
