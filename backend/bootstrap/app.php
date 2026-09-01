<?php

use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\SetResellerContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // ADR-047 decision 3: admin private-channel auth must run through this
    // app's real auth boundary (bearer-token Sanctum, per every other
    // endpoint in routes/api.php) — the framework's own default for
    // `withRouting(channels: ...)` registers an unprefixed
    // `/broadcasting/auth` behind the session-based `web` guard, which
    // this API-only backend never otherwise uses. Registered explicitly
    // here instead, at `POST /api/broadcasting/auth`, `auth:sanctum`-gated.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.role' => EnsureAdminRole::class,
            // ADR-058 (58a): activates ADR-057's tenant scope from the
            // authenticated reseller_user. Always paired with
            // `auth:reseller` on reseller-portal routes.
            'reseller.context' => SetResellerContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
