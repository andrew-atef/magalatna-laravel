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
        $todayCairo = now('Africa/Cairo')->toDateString();

        $retailers = Retailer::where('is_active', true)
            ->withCount(['flyers' => function ($q) use ($todayCairo): void {
                $q->where('status', FlyerStatus::Published)
                    ->whereDate('valid_until', '>=', $todayCairo);
            }])
            ->orderBy('name')
            ->get();

        $activeFlyers = Flyer::where('status', FlyerStatus::Published)
            ->whereDate('valid_until', '>=', $todayCairo)
            ->with('retailer')
            ->latest('valid_from')
            ->take(15)
            ->get();

        $content = Cache::remember('llms_txt_content', 21600, function () use ($retailers, $activeFlyers): string {
            return view('llms', compact('retailers', 'activeFlyers'))->render();
        });

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
