<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First super-admin bootstrap
    |--------------------------------------------------------------------------
    |
    | ADR-066 cutover addendum — the first-boot super admin is created by the
    | `app:create-admin` console command (run once by ProductionSeeder on the
    | very first deploy). These values are the fallback the command uses when
    | it is invoked with no --email/--password options.
    |
    | They MUST be read via config(), never env() at runtime: the deploy
    | script runs `php artisan config:cache`, after which Laravel stops
    | loading `.env` for artisan too, so a runtime `env('ADMIN_EMAIL')` comes
    | back null and the seeder silently skips. Reading them here means
    | `config:cache` bakes the resolved value in while `.env` is still loaded.
    |
    */

    'seed_email' => env('ADMIN_EMAIL'),
    'seed_password' => env('ADMIN_PASSWORD'),
    'seed_name' => env('ADMIN_NAME', 'Admin'),

];
