<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NegotiateMarkdownResponse
{
    /**
     * Edge response filter ONLY.
     *
     * Performs zero database queries and renders nothing. Controllers own
     * content negotiation and view selection; this middleware merely:
     *  - appends `Vary: Accept` to every response (CDN cache differentiation), and
     *  - tags AI/markdown responses with `X-Robots-Tag: noindex` (SEO protection).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ensure Vary header for CDN caching differentiation on every response.
        $response->headers->set('Vary', 'Accept', false);

        if (self::wantsMarkdown($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    public static function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->header('Accept');

        return str_contains($accept, 'text/markdown')
            || $request->query('_fmt') === 'md'
            || $request->query('format') === 'md';
    }
}
