<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Single source of truth for STATELESS PUBLIC routes.
 *
 * Shared by:
 *  - App\Http\Middleware\StatelessPublicPages (strips Set-Cookie from the
 *    response), and
 *  - the master layout (suppresses the dynamic <meta name="csrf-token">).
 *
 * `/admin`, `/livewire/*` and any named route NOT in this list keep the FULL
 * `web` session middleware — Filament and Livewire must never go stateless.
 */
final class StatelessRoutes
{
    /**
     * Named public GET routes rendered for anonymous visitors.
     *
     * @var list<string>
     */
    private const NAMES = [
        'home',
        'pages.about',
        'pages.privacy',
        'pages.terms',
        'pages.contact',
        'flyers.show',
        'retailers.show',
        'sitemap',
        'llms.txt',
    ];

    /**
     * Path-based fallbacks for requests WITHOUT a resolved route (CLI,
     * unrouted unit requests) — mirrors the named routes above 1:1. Retailer
     * hub pages are dynamic slugs and stay name-matched only.
     *
     * @var list<string>
     */
    private const PATHS = [
        '/',
        'about-us',
        'privacy-policy',
        'terms-of-use',
        'contact-us',
        'sitemap.xml',
        'llms.txt',
        'bim',
        'carrefour',
    ];

    public static function isStatelessRoute(Request $request): bool
    {
        // Only cacheable, read-only methods ever go stateless.
        if (! $request->isMethodCacheable()) {
            return false;
        }

        if (in_array((string) $request->route()?->getName(), self::NAMES, true)) {
            return true;
        }

        // Unrouted requests (CLI/tests) + legacy /flyer/{slug} and
        // /flyers/{slug} redirect closures have no route name — match by
        // path instead.
        $path = trim($request->path(), '/');
        if ($path === '') {
            $path = '/';
        }

        return in_array($path, self::PATHS, true)
            || preg_match('#^flyers?/[^/]+$#', $path) === 1
            || preg_match('#^offers/[^/]+$#', $path) === 1;
    }
}
