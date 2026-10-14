<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    // ADR-121: only the admin app is stateful (cookie session + CSRF). The
    // default derives from ADMIN_URL, which production already sets, so no
    // extra env var is needed. Never add the storefront or the portal: they
    // authenticate differently and must not receive admin cookies.
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', array_filter([
        'localhost:3000',
        parse_url((string) env('ADMIN_URL', ''), PHP_URL_HOST),
    ])))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    // Was `null` (never expires) — fresh audit, 2026-08-14, flagged an
    // indefinitely-lived admin bearer token as a real gap (leaked log/XSS/
    // stolen device grants permanent access). 720 min (12h) covers a full
    // admin shift without forcing a mid-session re-login; admin/lib/
    // api-client.ts now handles the resulting 401 by clearing the client
    // session and redirecting to /login instead of every page growing its
    // own handling. Since ADR-121 admins don't use tokens at all (cookie
    // session); this now bounds affiliate/reseller-portal tokens.
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 720),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
