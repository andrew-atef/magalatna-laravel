<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\StatelessRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless Public Pages — SEO/cacheable-response hygiene.
 *
 * The `web` middleware group boots a session for EVERY route (Filament and
 * Livewire need it), which means every anonymous homepage/flyer/hub GET also
 * emitted `Set-Cookie: laravel_session + XSRF-TOKEN`. That poisoned:
 *  - CDN cacheability (set-cookie on 200 HTML responses),
 *  - crawler neutrality (Googlebot/AI agents handed a session they ignore).
 *
 * This middleware runs LAST in the `web` group and, for STATELESS PUBLIC
 * GETs only, strips every Set-Cookie header from the response. The session
 * itself is untouched server-side — /admin, /livewire and logged-in users
 * keep full functionality.
 *
 * Runs AFTER StartSession (appended middleware) and is fully compatible with
 * NegotiateMarkdownResponse, which only appends Vary/X-Robots-Tag headers.
 */
final class StatelessPublicPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! StatelessRoutes::isStatelessRoute($request)) {
            return $response;
        }

        // Authenticated visitors MUST keep their cookies (Filament panel,
        // impersonation, etc.).
        if (app('auth')->check()) {
            return $response;
        }

        // A session carrying real payload (validation errors flashed after
        // the contact form POST, intended redirects, 2FA hints…) is NOT
        // stateless — keep its cookies so the UX round-trip survives.
        if ($request->hasSession() && self::sessionHasPayload($request->session()->all())) {
            return $response;
        }

        // Anonymous public GET → drop Set-Cookie entirely.
        $response->headers->remove('Set-Cookie');

        return $response;
    }

    /**
     * Machinery keys StartSession always writes vs. actual application data.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function sessionHasPayload(array $attributes): bool
    {
        $machinery = ['_token', '_flash', '_previous', '_sf2_meta'];

        return array_diff_key($attributes, array_flip($machinery)) !== [];
    }
}
