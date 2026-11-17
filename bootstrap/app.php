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
