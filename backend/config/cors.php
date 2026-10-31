<?php

/*
 * Cross-origin config for the browser-facing Next.js apps calling this API
 * directly — the Admin Panel (admin/, a Sanctum cookie session since
 * ADR-121, so it alone gets credentials; see `supports_credentials` below),
 * the guest-checkout Storefront (storefront/, ADR-011), and the Reseller
 * Portal (reseller/, ADR-059 — a Bearer-token app). Originally scoped
 * to ADMIN_URL only, from before storefront/ existed and called the API
 * directly from the browser — missing STOREFRONT_URL here silently broke
 * every browser-originated storefront call (checkout, player validation)
 * with a generic "failed to fetch", never surfaced as a CORS error to the
 * page's own JS, only visible in the Network tab's real response status.
 * The reseller portal (:3002) hit the exact same wall — its `/api/affiliate/*`
 * (né `/api/reseller/*`, ADR-072 PR-A rename) reads all failed as "Could not
 * load" until RESELLER_PORTAL_URL was added below.
 *
 * Laravel doesn't merge its own default cors.php unless this file exists in
 * config/ — without it, HandleCors matches zero paths and no CORS headers
 * are ever added, silently breaking every browser call from admin/ or
 * storefront/.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_merge(
        explode(',', env('ADMIN_URL', 'http://localhost:3000')),
        // storefront/ falls back to :3001 whenever admin/ already holds
        // :3000 (both started via scripts/dev.sh) — that fallback is the
        // common case locally, not an edge case, so it's in the default.
        explode(',', env('STOREFRONT_URL', 'http://localhost:3001')),
        // reseller/ portal (ADR-059) — pinned to :3002 (its `next dev -p`
        // and config/services.php's reseller_portal.url both use it).
        explode(',', env('RESELLER_PORTAL_URL', 'http://localhost:3002')),
        // ADR-133: the public reseller application, the same portal app on its own host.
        explode(',', env('ONBOARDING_URL', 'http://onboarding.localhost:3002')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Track Order's 429 recovery reads Laravel's throttle window from
    // browser JavaScript; without this, cross-origin fetch hides the header.
    'exposed_headers' => ['Retry-After'],

    // ADR-121 2026-10-19 addendum: a browser reuses a preflight answer for
    // that URL for two hours (Chrome's cap), instead of asking before every
    // mutation, portal (Bearer) or storefront (X-Storefront-Host) call. Safe:
    // the real response still needs Access-Control-Allow-Origin, so a
    // deactivated custom domain (ADR-078) is blocked on its next request.
    'max_age' => 7200,

    // Never true globally: a custom affiliate domain (ADR-078) must not get
    // credentials. ADR-121: AllowAdminCorsCredentials turns it on per
    // request for the origins below only (the admin app's cookie session).
    'supports_credentials' => false,

    'credentialed_origins' => array_filter(explode(',', env('ADMIN_URL', 'http://localhost:3000'))),

];
