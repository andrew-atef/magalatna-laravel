<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical host protection (SEO safety net).
 *
 * Cloudflare normally 301s www.magalatna.com -> magalatna.com at the edge.
 * If a www request ever reaches Laravel (edge rule drift, direct origin hit),
 * it must NOT render duplicate content under a second host: respond with a
 * permanent 301 to the apex, preserving path and query string.
 *
 * Deliberately scoped to exactly "www." + the config('app.url') host:
 * localhost / 127.0.0.1 / *.test dev hosts and the test suite are never
 * redirected, and unrelated hosts are left alone.
 */
final class VerifyCanonicalHost
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($canonicalHost === '') {
            return $next($request);
        }

        if (strtolower((string) $request->getHost()) !== 'www.'.$canonicalHost) {
            return $next($request);
        }

        // getRequestUri() = raw path + query string, byte-faithful.
        return redirect('https://'.$canonicalHost.$request->getRequestUri(), 301);
    }
}
