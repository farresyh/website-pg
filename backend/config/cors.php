<?php

/*
 * Cross-origin config for the Next.js Admin Panel (admin/) calling this API
 * directly from the browser (ADR-009 — Bearer token, not cookie-based
 * Sanctum SPA auth), so `supports_credentials` stays false: no cookie needs
 * to cross the origin boundary for auth.
 *
 * Laravel doesn't merge its own default cors.php unless this file exists in
 * config/ — without it, HandleCors matches zero paths and no CORS headers
 * are ever added, silently breaking every browser call from admin/.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('ADMIN_URL', 'http://localhost:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
