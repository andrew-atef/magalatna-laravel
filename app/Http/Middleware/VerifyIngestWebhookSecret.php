<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the shared radar ingest secret.
 *
 * Accepts the secret via:
 *   - X-Ingest-Secret: <secret>   (preferred — never logged by proxies)
 *   - ?secret=<secret>            (fallback for Cloudflare Workers that can't set headers)
 *
 * The expected value is read from config (never env() — env() is unavailable after
 * `php artisan config:cache` in production). Set INGEST_WEBHOOK_SECRET in your .env.
 */
final class VerifyIngestWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.ingest_webhook.secret', '');

        if (trim($expected) === '') {
            Log::warning('VerifyIngestWebhookSecret: INGEST_WEBHOOK_SECRET not configured; rejecting request.');

            return new JsonResponse(
                ['message' => 'Service unavailable. Ingest webhook secret not configured.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        // Prefer header; fall back to query param.
        $provided = $request->header('X-Ingest-Secret');

        if (! is_string($provided) || trim($provided) === '') {
            $provided = $request->query('secret');
        }

        if (! is_string($provided) || trim($provided) === '') {
            return new JsonResponse(
                ['message' => 'Unauthorized. Missing X-Ingest-Secret header or ?secret= parameter.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! hash_equals($expected, trim($provided))) {
            Log::warning('VerifyIngestWebhookSecret: invalid secret attempt.', [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
            ]);

            return new JsonResponse(
                ['message' => 'Unauthorized. Invalid ingest secret.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
