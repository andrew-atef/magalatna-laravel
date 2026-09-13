<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class FlyerController extends Controller
{
    /**
     * @return View|Response
     */
    public function show(Request $request, string $slug): View|Response
    {
        $flyer = Flyer::with([
            'retailer',
            'pages' => fn ($q) => $q->orderBy('page_number'),
            'items.brand',
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();

        $sameRetailerFlyers = Flyer::with(['retailer', 'coverPage'])
            ->where('retailer_id', $flyer->retailer_id)
            ->where('id', '!=', $flyer->id)
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->limit(4)
            ->get();

        $competitorFlyers = Flyer::with(['retailer', 'coverPage'])
            ->where('retailer_id', '!=', $flyer->retailer_id)
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->limit(6)
            ->get();

        $archiveFlyers = Flyer::with(['retailer', 'coverPage'])
            ->where('retailer_id', $flyer->retailer_id)
            ->where('valid_until', '<', $cairoToday)
            ->latest('valid_until')
            ->limit(3)
            ->get();

        $wantsMarkdown = $request->header('Accept') === 'text/markdown'
            || str_contains((string) $request->header('Accept'), 'text/markdown')
            || $request->query('_fmt') === 'md'
            || $request->query('format') === 'md';

        if ($wantsMarkdown) {
            return response()
                ->view('flyers.show-markdown', compact('flyer', 'sameRetailerFlyers', 'competitorFlyers', 'archiveFlyers'))
                ->header('Content-Type', 'text/markdown; charset=UTF-8')
                ->header('Vary', 'Accept')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return view('flyers.show', compact('flyer', 'sameRetailerFlyers', 'competitorFlyers', 'archiveFlyers'));
    }
}
