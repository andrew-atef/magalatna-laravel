<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Flyer;
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

        return view('flyers.show', compact('flyer'));
    }
}
