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
     * Validates Bearer token against INTERNAL_API_KEY.
     * Accepts either `Authorization: Bearer <key>` or `X-Internal-Key` header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $expected = (string) (config('services.internal_api.key') ?? config('services.internal_api_key') ?? env('INTERNAL_API_KEY', ''));

            if (trim($expected) === '') {
                Log::warning('INTERNAL_API_KEY is not configured; rejecting ingestion request.');

                return new JsonResponse(['message' => 'Internal API key not configured.'], 500);
            }

            $provided = $request->bearerToken();

            if ($provided === null || trim($provided) === '') {
                $provided = $request->header('X-Internal-Key');
            }

            if (! is_string($provided) || trim($provided) === '') {
                return new JsonResponse(['message' => 'Unauthorized. Missing Bearer token.'], 401);
            }

            if (! hash_equals($expected, trim($provided))) {
                Log::warning('Invalid internal API key attempt.', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return new JsonResponse(['message' => 'Unauthorized. Invalid token.'], 401);
            }

            return $next($request);
        } catch (\Throwable $e) {
            Log::error('InternalApiKeyMiddleware failed.', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['message' => 'Unauthorized.'], 401);
        }
    }
}
