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
    public function show(Request $request, string $slug): View|Response
    {
        // Public visibility guard: Draft/PendingReview must NEVER leak, and
        // flyers of deactivated retailers must 404 (SEO + content hygiene).
        $flyer = Flyer::with([
            'retailer',
            'pages' => fn ($q) => $q->orderBy('page_number'),
            'items.brand',
        ])
            ->where('slug', $slug)
            ->whereIn('status', [FlyerStatus::Published, FlyerStatus::Expired])
            ->whereHas('retailer', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();

        // Moved OUT of the Blade view: the expired-flyer banner's "new flyer
        // available" link resolves here — zero DB queries inside views.
        // Only expired flyers render that banner, so active flyers skip the
        // wasted SQLite query entirely.
        $latestActiveFlyer = null;
        if ($flyer->isExpired()) {
            $latestActiveFlyer = $flyer->retailer->flyers()
                ->where('status', FlyerStatus::Published)
                // Direct >= comparison so the valid_until B-Tree index is used
                // (whereDate() wraps the column and forces a full scan on SQLite).
                ->where('valid_until', '>=', $cairoToday)
                ->latest('valid_from')
                ->first(['id', 'slug', 'title', 'retailer_id']);
        }

        // withCount('pages') powers "N صفحة" badges via pages_count — hydrating
        // whole pages relations in widgets wasted MBs of RAM.
        $sameRetailerFlyers = Flyer::with(['retailer', 'coverPage'])
            ->withCount('pages')
            ->where('retailer_id', $flyer->retailer_id)
            ->where('id', '!=', $flyer->id)
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->latest('valid_from')
            ->limit(4)
            ->get();

        $competitorFlyers = Flyer::with(['retailer', 'coverPage'])
            ->withCount('pages')
            ->where('retailer_id', '!=', $flyer->retailer_id)
            ->where('status', FlyerStatus::Published)
            ->where('valid_until', '>=', $cairoToday)
            ->whereHas('retailer', fn ($q) => $q->where('is_active', true))
            ->latest('valid_from')
            ->limit(6)
            ->get();

        // Archive: same retailer, strictly historical, never leak Drafts or
        // self-reference; Expired flyers count as archive entries too.
        $archiveFlyers = Flyer::with(['retailer', 'coverPage'])
            ->withCount('pages')
            ->where('retailer_id', $flyer->retailer_id)
            ->where('id', '!=', $flyer->id)
            ->whereIn('status', [FlyerStatus::Published, FlyerStatus::Expired])
            ->where('valid_until', '<', $cairoToday)
            ->latest('valid_until')
            ->limit(3)
            ->get();

        $wantsMarkdown = $request->header('Accept') === 'text/markdown'
            || str_contains((string) $request->header('Accept'), 'text/markdown')
            || $request->query('_fmt') === 'md'
            || $request->query('format') === 'md';

        if ($wantsMarkdown) {
            // AI-optimised markdown variant: canonical stays the HTML URL.
            $canonical = rtrim((string) config('app.url'), '/')
                .route('flyers.show', ['slug' => $flyer->slug], false);

            return response()
                ->view('flyers.show-markdown', compact('flyer', 'sameRetailerFlyers', 'competitorFlyers', 'archiveFlyers', 'latestActiveFlyer'))
                ->header('Content-Type', 'text/markdown; charset=UTF-8')
                ->header('Vary', 'Accept')
                ->header('X-Robots-Tag', 'noindex, follow')
                ->header('Link', "<{$canonical}>; rel=\"canonical\"")
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return response()
            ->view('flyers.show', compact('flyer', 'sameRetailerFlyers', 'competitorFlyers', 'archiveFlyers', 'latestActiveFlyer'))
            ->header('Vary', 'Accept');
    }
}
