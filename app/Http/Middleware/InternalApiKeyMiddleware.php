<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class InternalApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Validates the caller against the server-side internal API key.
     * The expected key is read STRICTLY from config (never env() — env() is
     * unavailable after `php artisan config:cache` in production).
     * Accepts either `Authorization: Bearer <key>` or `X-Internal-Key` header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal_api.key', '');

        if (trim($expected) === '') {
            Log::warning('InternalApiKeyMiddleware: internal API key not configured; rejecting request.');

            return new JsonResponse(['message' => 'Service unavailable. Internal API key not configured.'], 503);
        }

        $provided = $request->bearerToken();

        if ($provided === null || trim($provided) === '') {
            $provided = $request->header('X-Internal-Key');
        }

        if (! is_string($provided) || trim($provided) === '') {
            return new JsonResponse(['message' => 'Unauthorized. Missing credentials.'], 401);
        }

        if (! hash_equals($expected, trim($provided))) {
            Log::warning('InternalApiKeyMiddleware: invalid internal API key attempt.', [
                'ip' => $request->ip(),
            ]);

            return new JsonResponse(['message' => 'Unauthorized. Invalid credentials.'], 401);
        }

        return $next($request);
    }
}
