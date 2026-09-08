<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\View\View;

final class FlyerController extends Controller
{
    public function show(string $slug): View
    {
        $flyer = Flyer::with([
            'retailer',
            'pages' => fn ($q) => $q->orderBy('page_number'),
            'items.brand',
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();

        $sameRetailerFlyers = Flyer::with(['retailer', 'pages'])
            ->where('retailer_id', $flyer->retailer_id)
            ->where('id', '!=', $flyer->id)
            ->where('status', 'published')
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->limit(4)
            ->get();

        $competitorFlyers = Flyer::with(['retailer', 'pages'])
            ->where('retailer_id', '!=', $flyer->retailer_id)
            ->where('status', 'published')
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->limit(6)
            ->get();

        $archiveFlyers = Flyer::with(['retailer', 'pages'])
            ->where('retailer_id', $flyer->retailer_id)
            ->where('valid_until', '<', $cairoToday)
            ->latest('valid_until')
            ->limit(3)
            ->get();

        return view('flyers.show', compact('flyer', 'sameRetailerFlyers', 'competitorFlyers', 'archiveFlyers'));
    }
}
