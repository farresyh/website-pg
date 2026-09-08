<?php

use App\Http\Middleware\AllowActiveCustomDomainCors;
use App\Http\Middleware\EnsureAccountType;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureBrandMembershipEnabled;
use App\Http\Middleware\ResolveStorefrontBrand;
use App\Http\Middleware\SetAffiliateContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

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
        // ADR-078 decision 1: runs before the framework's HandleCors
        // (prepended to the global stack) so it can add an active custom
        // affiliate-domain (ADR-060) to `cors.allowed_origins` for this
        // request. Gated on the `Origin` header — a non-CORS request does
        // no work. See the middleware's own doc comment for why this is
        // not a rebound CorsService.
        $middleware->prepend(AllowActiveCustomDomainCors::class);

        // Production is Laravel Forge (native nginx → php-fpm, ADR-066) with
        // Cloudflare proxying `api.pekangame.space` (ADR-020 decision 8 /
        // the 2026-09-06 Cloudflare cutover). The immediate peer nginx sees
        // is a Cloudflare edge IP, so trust exactly Cloudflare's published
        // ranges (plus loopback for local health checks) — NOT `*`, which
        // would let any caller spoof `X-Forwarded-For` and defeat the
        // per-IP rate limiters, the FRAUD-4 checkout velocity guard, and
        // the auth/impersonation audit logs. Cloudflare always appends the
        // true connecting IP as the right-most `X-Forwarded-For` entry, so
        // Symfony's right-to-left walk past trusted proxies lands on the
        // real client; `$request->ip()`/`secure()`/`getHost()` are then
        // correct everywhere with no per-call-site change.
        //
        // Ranges from https://www.cloudflare.com/ips/ (fetched 2026-09-06).
        // Cloudflare changes these rarely; re-check on any real-IP anomaly.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            // Cloudflare IPv4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // Cloudflare IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'admin.role' => EnsureAdminRole::class,
            // ADR-058 (58a): activates ADR-057's tenant scope from the
            // authenticated affiliate_user. Always paired with
            // `auth:affiliate` on affiliate-portal routes.
            'affiliate.context' => SetAffiliateContext::class,
            // ADR-072 decision 4 / PR-G: the owner_type gate —
            // `account.type:affiliate` / `account.type:reseller` — on
            // every `affiliate`-guard route (both the existing Affiliate
            // portal and the new Reseller wallet portal).
            'account.type' => EnsureAccountType::class,
            // ADR-060 (2026-09-06 addendum): resolves the storefront brand
            // from `X-Storefront-Host` on the public storefront routes.
            // Inert without the header (falls back to Affiliate::primary()).
            'storefront.brand' => ResolveStorefrontBrand::class,
            // ADR-080 decision 1/2: the single 403 gate for the
            // /membership sales & write surface (OTP send/verify,
            // subscribe-options, subscribe). `plans` and `me` are
            // deliberately outside it.
            'membership.enabled' => EnsureBrandMembershipEnabled::class,
        ]);

        // ADR-080: `ThrottleRequests` sits in the framework's default
        // middleware-priority list, so without this a route's
        // `['membership.enabled', 'throttle:*']` array can still run the
        // limiter first — a disabled brand's requests would then burn a
        // rate-limit bucket before the 403. Pin the gate ahead of it.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: EnsureBrandMembershipEnabled::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
