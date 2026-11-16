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
        // Config-only (never env() — env() returns null after config:cache).
        // services.ingest_webhook.secret => env('INGEST_WEBHOOK_SECRET').
        $expectedIngest = trim((string) config('services.ingest_webhook.secret', ''));
        $expectedInternal = trim((string) config('services.internal_api.key', ''));

        // Deprecation radar: ?secret= leaks into Cloudflare/proxy access logs.
        // Clients MUST move to the X-Ingest-Secret header.
        if ($request->has('secret')) {
            Log::warning('VerifyIngestWebhookSecret: secret supplied via query string. ?secret= is deprecated and leaks into CDN/proxy logs — switch to the X-Ingest-Secret header.', [
                'ip' => $request->ip(),
            ]);
        }

        // Extract secret from header, query param, or bearer/internal key
        $providedSecret = $request->header('X-Ingest-Secret') ?? $request->query('secret');
        $providedBearer = $request->bearerToken() ?? $request->header('X-Internal-Key');

        // Check against INGEST_WEBHOOK_SECRET
        if ($expectedIngest !== '' && is_string($providedSecret) && trim($providedSecret) !== '') {
            if (hash_equals($expectedIngest, trim($providedSecret))) {
                return $next($request);
            }
        }

        // Fallback: check if caller provided INGEST_WEBHOOK_SECRET via Bearer/X-Internal-Key
        if ($expectedIngest !== '' && is_string($providedBearer) && trim($providedBearer) !== '') {
            if (hash_equals($expectedIngest, trim($providedBearer))) {
                return $next($request);
            }
        }

        // Fallback: check if caller provided INTERNAL_API_KEY
        if ($expectedInternal !== '') {
            $candidate = is_string($providedSecret) && trim($providedSecret) !== '' ? $providedSecret : $providedBearer;
            if (is_string($candidate) && trim($candidate) !== '' && hash_equals($expectedInternal, trim($candidate))) {
                return $next($request);
            }
        }

        if ($expectedIngest === '' && $expectedInternal === '') {
            Log::warning('VerifyIngestWebhookSecret: Neither INGEST_WEBHOOK_SECRET nor INTERNAL_API_KEY configured.');

            return new JsonResponse(
                ['message' => 'Service unavailable. Ingest secret not configured on server.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        Log::warning('VerifyIngestWebhookSecret: invalid or missing secret attempt.', [
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'has_x_ingest_secret' => $request->hasHeader('X-Ingest-Secret'),
            'has_secret_query' => $request->has('secret'),
        ]);

        return new JsonResponse(
            ['message' => 'Unauthorized. Missing or invalid X-Ingest-Secret header or ?secret= parameter.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
