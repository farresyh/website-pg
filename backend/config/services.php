<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Shared outbound HTTP(S) proxy, used by any SupplierAdapter whose
    // supplier requires a whitelisted static IP — our local/dev machine
    // has no static IP of its own. Not tied to Gamevion specifically:
    // one proxy host covers every supplier that needs IP whitelisting,
    // since the whitelisted party only cares which IP the request came
    // from, not what else that IP is also used for. Only build a second
    // proxy if a future supplier demands an exclusive/region-specific IP.
    // SUPPLIER_PROXY_URL format: http://user:pass@host:port
    'proxy' => [
        'enabled' => (bool) env('SUPPLIER_PROXY_ENABLED', false),
        'url' => env('SUPPLIER_PROXY_URL'),
    ],

    // Temporary env-based config for local/manual testing of
    // GamevionAdapter. Once the Supplier model exists (SUPP-5), this
    // moves to encrypted-at-rest per-supplier `api_config` in the DB —
    // do not build admin UI around these env vars.
    'gamevion' => [
        'base_url' => env('GAMEVION_BASE_URL', 'https://api.gamevion.com'),
        'bearer_token' => env('GAMEVION_BEARER_TOKEN'),
        'api_key' => env('GAMEVION_API_KEY'),
        'sandbox' => env('GAMEVION_SANDBOX', true),
        // createOrder() runs inside a DB row lock (OrderFulfillmentService)
        // — keep these short so a hung supplier response can't hold that
        // lock open indefinitely. See GamevionAdapter::client().
        'timeout' => (int) env('GAMEVION_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('GAMEVION_CONNECT_TIMEOUT_SECONDS', 5),
    ],

    // ADR-019 addendum: per-supplier circuit breaker (App\Services\
    // CircuitBreaker\CircuitBreaker) - trips after this many consecutive
    // server-error (5xx) responses, stays open for the cooldown window.
    // Shared across every SupplierAdapter wrapped by
    // CircuitBreakingSupplierAdapter, not just Gamevion.
    'circuit_breaker' => [
        'failure_threshold' => (int) env('SUPPLIER_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('SUPPLIER_CIRCUIT_BREAKER_COOLDOWN_SECONDS', 60),
    ],

    // Temporary env-based config for local/manual testing of
    // XenditGateway. Test vs. live mode is controlled by which key
    // type is set (Xendit's own convention: xnd_development_... vs
    // xnd_production_...), not a separate sandbox URL/flag.
    'xendit' => [
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        // ADR-019: keep short so a slow Xendit response can't hold a
        // customer-facing checkout request thread open indefinitely,
        // compounded across retry attempts. Same discipline as
        // GamevionAdapter's own timeout config above.
        'timeout' => (int) env('XENDIT_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('XENDIT_CONNECT_TIMEOUT_SECONDS', 5),
    ],

    // Unofficial third-party MLBB validators (docs/prd.md research,
    // confirmed live 2026-07-25) — no API keys, since all three are
    // public unauthenticated form/JSON endpoints. Short timeouts since
    // MlbbPlayerValidator tries them in sequence synchronously within
    // one storefront request; a slow/hung provider must fail fast so
    // the next one in the chain still gets a chance.
    'player_validators' => [
        'acidgameshop' => [
            'base_url' => env('ACIDGAMESHOP_BASE_URL', 'https://acidgameshop.com'),
            'timeout' => (int) env('ACIDGAMESHOP_TIMEOUT_SECONDS', 8),
        ],
        'nexone' => [
            'base_url' => env('NEXONE_BASE_URL', 'https://nexone.ph'),
            'timeout' => (int) env('NEXONE_TIMEOUT_SECONDS', 8),
        ],
        'moogold' => [
            'base_url' => env('MOOGOLD_BASE_URL', 'https://moogold.com'),
            'timeout' => (int) env('MOOGOLD_TIMEOUT_SECONDS', 8),
        ],
    ],

    // How long a `player_validations` status=valid row (see
    // PlayerValidationController) stays acceptable proof for
    // CheckoutController's server-side re-check. Loose enough that a
    // customer moving through the storefront wizard at normal pace
    // never gets rejected for their own genuine validation going stale.
    'player_validation' => [
        'checkout_window_minutes' => (int) env('PLAYER_VALIDATION_CHECKOUT_WINDOW_MINUTES', 30),
    ],

    // Same STOREFRONT_URL env var cors.php already reads (may be
    // comma-separated when multiple origins are allowed) — first entry
    // is the canonical origin used to build the per-order redirect URL
    // Xendit sends the customer back to after hosted-page payment. See
    // CheckoutService::requestPayment().
    'storefront' => [
        'url' => explode(',', env('STOREFRONT_URL', 'http://localhost:3001'))[0],
    ],

];
