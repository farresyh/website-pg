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

    // Temporary env-based config for local/manual testing of
    // GamevionAdapter. Once the Supplier model exists (SUPP-5), this
    // moves to encrypted-at-rest per-supplier `api_config` in the DB —
    // do not build admin UI around these env vars.
    'gamevion' => [
        'base_url' => env('GAMEVION_BASE_URL', 'https://api.gamevion.com'),
        'bearer_token' => env('GAMEVION_BEARER_TOKEN'),
        'api_key' => env('GAMEVION_API_KEY'),
        'sandbox' => env('GAMEVION_SANDBOX', true),
    ],

];
