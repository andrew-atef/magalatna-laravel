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

        // Merge `Accept` into any existing Vary value — Symfony's set(..., false)
        // would append a SECOND Vary header line, which some CDN tiers treat
        // as malformed and silently drop (cache-key fragmentation).
        $existingVary = (string) $response->headers->get('Vary', '');
        $varyTokens = array_values(array_filter(array_map(
            static fn (string $t): string => trim($t),
            explode(',', $existingVary),
        ), static fn (string $t): bool => $t !== ''));

        if (! in_array('Accept', $varyTokens, true)) {
            $varyTokens[] = 'Accept';
        }

        $response->headers->set('Vary', implode(', ', $varyTokens));

        if (self::wantsMarkdown($request) && ! $response->headers->has('X-Robots-Tag')) {
            // Fallback only: controllers set the richer "noindex, follow"
            // (plus canonical Link headers); never downgrade it to "noindex".
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
