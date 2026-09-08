<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NegotiateMarkdownResponse
{
    /**
     * Handle an incoming request.
     *
     * Checks if client wants Markdown via Accept header or ?_fmt=md.
     * Controllers also perform same check for view selection; this middleware
     * ensures Vary header is set even for HTML responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $wantsMarkdown = $this->wantsMarkdown($request);

        // Ensure Vary header for CDN caching differentiation
        $response->headers->set('Vary', 'Accept', false);

        if ($wantsMarkdown && $response->headers->get('Content-Type') !== 'text/markdown; charset=UTF-8') {
            // Controllers already return markdown with correct Content-Type when needed;
            // middleware just ensures Vary is present.
        }

        return $response;
    }

    public static function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->header('Accept');

        return $accept === 'text/markdown'
            || str_contains($accept, 'text/markdown')
            || $request->query('_fmt') === 'md';
    }
}
