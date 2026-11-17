<?php

use App\Http\Middleware\InternalApiKeyMiddleware;
use App\Http\Middleware\VerifyIngestWebhookSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'internal.api'   => InternalApiKeyMiddleware::class,
            'ingest.secret'  => VerifyIngestWebhookSecret::class,
        ]);
        // GLOBAL (outermost) cookie stripper: StartSession and
        // ValidateCsrfToken re-attach their cookies on the way BACK UP the
        // middleware onion, so an inner (web-group) stripper would be
        // re-populated. As a global wrapper it runs LAST on the response and
        // cleanly removes Set-Cookie from anonymous stateless public GETs
        // only — /admin, /livewire and any non-public route keep their
        // full web session middleware.
        $middleware->append(\App\Http\Middleware\StatelessPublicPages::class);
        $middleware->web(append: [
            // Canonical host protection FIRST: a www.magalatna.com request is
            // 301'd to the apex before any session/view work happens.
            \App\Http\Middleware\VerifyCanonicalHost::class,
            \App\Http\Middleware\NegotiateMarkdownResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
