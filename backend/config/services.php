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

    // ADR-030 — same temporary env-based stopgap as 'gamevion' above,
    // same reasoning (moves to encrypted Supplier.api_config once a
    // real Digiflazz account exists to configure through the UI).
    'digiflazz' => [
        'base_url' => env('DIGIFLAZZ_BASE_URL', 'https://api.digiflazz.com'),
        'username' => env('DIGIFLAZZ_USERNAME'),
        'api_key' => env('DIGIFLAZZ_API_KEY'),
        // ADR-030 decision 3 — sent per-request only when true, mirrors
        // GAMEVION_SANDBOX's own env-flag pattern.
        'testing' => env('DIGIFLAZZ_TESTING', true),
        // ADR-030 decision 5 — the exact MLBB customer_no format isn't
        // documented publicly; verified live at app:digiflazz-smoke-test
        // once the account/IP whitelist is confirmed. Best-guess default
        // mirrors GamevionAdapter's own playerId|serverId pipe convention.
        'customer_no_separator' => env('DIGIFLAZZ_CUSTOMER_NO_SEPARATOR', '|'),
        'timeout' => (int) env('DIGIFLAZZ_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('DIGIFLAZZ_CONNECT_TIMEOUT_SECONDS', 5),
        // ADR-069 decision 3 — the inbound-webhook IP allowlist, a
        // config-driven second gate behind the HMAC signature (which is
        // the primary auth). Digiflazz's API-setup docs name
        // 52.74.250.133; kept editable here so an added Digiflazz IP is
        // a config change, not a deploy. The daily reconcile poll is the
        // backstop if this ever goes stale. Comma-separated in env.
        'webhook_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DIGIFLAZZ_WEBHOOK_IPS', '52.74.250.133')),
        ))),
    ],

    // ADR-033 — keyless, no API key needed. CurrencyRateService reads
    // this directly (no per-currency-pair adapter, so no AppServiceProvider
    // binding needed the way gamevion/digiflazz's own adapters have one).
    'fx_api' => [
        'base_url' => env('FX_API_BASE_URL', 'https://open.er-api.com/v6/latest'),
        'source' => env('FX_API_SOURCE', 'open.er-api.com'),
        // ADR-033 decision 1 — ~12-24h: the free tier updates once
        // daily, and a same-day cache keeps a single sync run
        // deterministic (every package priced from the same fetch).
        'cache_ttl_seconds' => (int) env('FX_API_CACHE_TTL_SECONDS', 86400),
        'timeout' => (int) env('FX_API_TIMEOUT_SECONDS', 5),
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

    /**
     * ADR-022 — CHIP Collect (docs.chip-in.asia). No separate
     * sandbox base URL is documented; CHIP tests using the same
     * production endpoint with test-mode API keys (confirmed against
     * CHIP's own OpenAPI spec, 2026-08-03 — don't assume otherwise).
     * `webhook_public_key_ttl` controls how long ChipGateway caches
     * the RSA public key fetched from `GET /public_key/` before
     * re-fetching (webhook verification, ADR-022 decision 3).
     *
     * `callback_url` is the server-to-server `success_callback` CHIP
     * POSTs a signed Purchase to when a purchase is paid (ADR-022's
     * 2026-09-04 webhook-model addendum: per-purchase `success_callback`,
     * not a portal-registered webhook). Defaults to this app's own
     * `/api/webhooks/chip` route under `APP_URL`; the explicit override
     * exists only for pointing a local tunnel at it during go-live
     * testing without touching `APP_URL`.
     */
    'chip' => [
        'base_url' => env('CHIP_BASE_URL', 'https://gate.chip-in.asia/api/v1'),
        'secret_key' => env('CHIP_SECRET_KEY'),
        'brand_id' => env('CHIP_BRAND_ID'),
        'timeout' => (int) env('CHIP_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('CHIP_CONNECT_TIMEOUT_SECONDS', 5),
        'webhook_public_key_ttl' => (int) env('CHIP_WEBHOOK_PUBLIC_KEY_TTL_SECONDS', 86400),
        'callback_url' => env('CHIP_CALLBACK_URL', rtrim((string) env('APP_URL'), '/').'/api/webhooks/chip'),
    ],

    /**
     * ADR-071 PR2 — the storefront's Next.js `catalog` Data-Cache tag is
     * purged on any catalog/SEO/branding/hero/payment mutation by
     * POSTing to its `/api/revalidate` route (mirroring this backend's
     * own `forgetCache()` discipline one layer up). Both values unset =
     * no-op (local dev, CI, tests): the storefront still self-refreshes
     * on its 60s TTL. `revalidate_url` is the storefront origin +
     * `/api/revalidate`.
     */
    'next' => [
        'revalidate_url' => env('NEXT_REVALIDATE_URL'),
        'revalidate_secret' => env('NEXT_REVALIDATE_SECRET'),
        'revalidate_timeout' => (int) env('NEXT_REVALIDATE_TIMEOUT_SECONDS', 8),
    ],

    /**
     * ADR-027's 2026-08-29 addendum, decisions 27/29 — email OTP
     * delivery for membership verification (Meta/WhatsApp evaluated and
     * rejected on cost per Decision 27). Base URL confirmed live against
     * Plunk's own API reference (docs.useplunk.com/api-reference/overview,
     * 2026-08-29) — a secret key (sk_*), Bearer auth. Genuinely new
     * external dependency: no key exists yet, same "config placeholder
     * until provisioned" pattern as Xendit/Gamevion when they were first
     * wired.
     */
    'plunk' => [
        'base_url' => env('PLUNK_BASE_URL', 'https://next-api.useplunk.com'),
        'api_key' => env('PLUNK_API_KEY'),
        'from_email' => env('PLUNK_FROM_EMAIL', 'no-reply@send.fixfastapp.com'),
        'from_name' => env('PLUNK_FROM_NAME', 'FixFastApp'),
        'timeout' => (int) env('PLUNK_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('PLUNK_CONNECT_TIMEOUT_SECONDS', 5),
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
    // retention_days (ADR-021): a player_validations row is written on
    // every storefront validation attempt, not just completed orders, so
    // it accumulates PII faster than real order volume — 7 days, grilled
    // down from an initial 30-day proposal, is judged enough for a
    // realistic support/troubleshooting window. See
    // PrunePlayerValidationsCommand.
    'player_validation' => [
        'checkout_window_minutes' => (int) env('PLAYER_VALIDATION_CHECKOUT_WINDOW_MINUTES', 30),
        'retention_days' => (int) env('PLAYER_VALIDATION_RETENTION_DAYS', 7),
    ],

    // ADR-051 decision 7: `validate_player` rows carry the same
    // player-account nickname/ID category player_validation.retention_days
    // above already governs, so they follow that same 7-day window
    // rather than the blanket default — see
    // PruneSupplierRequestLogsCommand.
    'supplier_request_log' => [
        'retention_days' => (int) env('SUPPLIER_REQUEST_LOG_RETENTION_DAYS', 30),
        'validate_player_retention_days' => (int) env('SUPPLIER_REQUEST_LOG_VALIDATE_PLAYER_RETENTION_DAYS', 7),
    ],

    // Same STOREFRONT_URL env var cors.php already reads (may be
    // comma-separated when multiple origins are allowed) — first entry
    // is the canonical origin used to build the per-order redirect URL
    // CHIP sends the customer back to after hosted-page payment. See
    // CheckoutService::requestPayment().
    'storefront' => [
        'url' => explode(',', env('STOREFRONT_URL', 'http://localhost:3001'))[0],
    ],

    // ADR-058 (58a) — canonical origin of the reseller portal (ADR-059),
    // used to build the absolute set-password invite link
    // (AffiliateInviteService). Distinct deploy target from the storefront.
    // Key/env var stay "reseller_portal"/RESELLER_PORTAL_URL post-ADR-072:
    // this is the shared reseller/ Next.js app itself (ADR-072 decision 3
    // — both Affiliate and, later, Reseller-wallet accounts log into the
    // same portal), not the renamed Affiliate entity.
    'reseller_portal' => [
        'url' => explode(',', env('RESELLER_PORTAL_URL', 'http://localhost:3002'))[0],
    ],

    // ADR-021 (PAY-3) — ReconcilePendingPaymentsCommand's own thresholds.
    // pending_after_minutes: how stale a payment_status=pending order must
    // be before it's even worth asking the gateway about (a checkout from
    // 30 seconds ago just hasn't had its webhook arrive yet — not a real
    // gap). flag_after_hours: the fallback safety-net cap — no terminal
    // answer from the gateway after this long means "flag for admin
    // review", never "assume failed", since the gateway state might still
    // be genuinely open (grilled explicitly in ADR-021, not decided by
    // elapsed time alone).
    'payment_reconciliation' => [
        'pending_after_minutes' => (int) env('PAYMENT_RECONCILIATION_PENDING_AFTER_MINUTES', 30),
        'flag_after_hours' => (int) env('PAYMENT_RECONCILIATION_FLAG_AFTER_HOURS', 24),
    ],

    // ADR-068 decision 10 / S16 — the self-serve membership-subscription
    // equivalent of payment_reconciliation above. A pending
    // membership_checkout_attempts row older than `pending_after_minutes`
    // is checked against the gateway; one still not paid after
    // `expire_after_hours` is marked `expired` (abandoned).
    'membership_reconciliation' => [
        'pending_after_minutes' => (int) env('MEMBERSHIP_RECONCILIATION_PENDING_AFTER_MINUTES', 30),
        'expire_after_hours' => (int) env('MEMBERSHIP_RECONCILIATION_EXPIRE_AFTER_HOURS', 24),
    ],

    // ADR-073 decision 3(a) / PR-G planning addendum decision 9 — the
    // self-serve wallet-top-up equivalent of payment_reconciliation
    // above. Unlike a membership attempt (soft expire_after_hours), a
    // WalletTopupAttempt already carries its own hard 30-minute
    // expires_at (decision 8) — this command's own "expire" branch acts
    // on that column directly, no separate expire-after config needed.
    // pending_after_minutes is shorter than payment_reconciliation's own
    // 30 (a top-up's whole window is only 30 minutes) — long enough
    // that a webhook genuinely just hasn't arrived yet isn't mistaken
    // for stuck.
    'wallet_topup_reconciliation' => [
        'pending_after_minutes' => (int) env('WALLET_TOPUP_RECONCILIATION_PENDING_AFTER_MINUTES', 5),
    ],

    // ADR-026 (ORD-10) — 15 minutes comfortably exceeds FulfillOrderJob's
    // own worst-case retry-exhaustion window (HTTP-layer + job-layer
    // retries combined), so anything still stuck past this point is
    // genuinely ambiguous, not just "still retrying".
    'delivery_reconciliation' => [
        'stale_after_minutes' => (int) env('DELIVERY_RECONCILIATION_STALE_AFTER_MINUTES', 15),

        // ADR-032 decision 6 — defaults for an async supplier's own
        // "don't re-check within N minutes" guard (Digiflazz docs:
        // don't re-call the same transaction within 1 minute).
        // Overridable per-supplier via Supplier.api_config['pending_stale_minutes'].
        'pending_stale_minutes' => (int) env('DELIVERY_RECONCILIATION_PENDING_STALE_MINUTES', 10),

        // Digiflazz docs: never re-submit a ref_id older than 90 days
        // — it creates a NEW transaction rather than checking the old
        // one. A Pending order this old is auto-flagged for manual
        // review instead of polled. Overridable per-supplier via
        // Supplier.api_config['max_reconcile_age_days'].
        'max_reconcile_age_days' => (int) env('DELIVERY_RECONCILIATION_MAX_RECONCILE_AGE_DAYS', 90),
    ],

    // ADR-075 / PR-F build addendum — self-hosted OpenWA (github.com/
    // rmyndharis/OpenWA), one shared WhatsApp session/number for every
    // Reseller Bot-channel account. `engine` is OpenWA's own deployment
    // config (ENGINE_TYPE env var on that process, not read by this app
    // at all) — recorded here only as a comment: baileys first (PR-F
    // build addendum decision 1), whatsapp-web.js later at the
    // founder's own manual review, no automated switch trigger.
    // `webhook_signature_header`/`_algo` are a best-guess default —
    // OpenWA's own docs don't publish the exact header/algorithm the
    // way Digiflazz's do — same "confirmed once the real account exists"
    // posture `digiflazz.customer_no_separator` above already carries;
    // correct at actual OpenWA provisioning time, not assumed here.
    'openwa' => [
        'base_url' => env('OPENWA_BASE_URL', 'http://127.0.0.1:2785'),
        'session_id' => env('OPENWA_SESSION_ID'),
        'api_key' => env('OPENWA_API_KEY'),
        'webhook_secret' => env('OPENWA_WEBHOOK_SECRET'),
        'webhook_signature_header' => env('OPENWA_WEBHOOK_SIGNATURE_HEADER', 'X-Webhook-Signature'),
        'webhook_signature_algo' => env('OPENWA_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
        'timeout' => (int) env('OPENWA_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('OPENWA_CONNECT_TIMEOUT_SECONDS', 5),

        // PR-F build addendum decision 3 — how long an unmatched
        // group's pending-link row survives before app:prune-reseller-
        // whatsapp-pending-links deletes it.
        'pending_link_ttl_hours' => (int) env('OPENWA_PENDING_LINK_TTL_HOURS', 24),

        // PR-F build addendum decision 2 — reseller_bot_command_logs
        // retention, matching player_validations' own PII-adjacent window.
        'command_log_retention_days' => (int) env('OPENWA_COMMAND_LOG_RETENTION_DAYS', 7),
    ],

];
