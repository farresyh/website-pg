#!/usr/bin/env bash
set -euo pipefail

# ADR-023 decision #8 — a dedicated, minimal E2E boot script, not
# scripts/dev.sh. Reusing dev.sh would inherit its two documented
# gotchas (silent queue-worker death if backend/node_modules is
# missing; migrations not auto-applying to the dev DB) as E2E-suite
# gotchas too, undermining ADR-023 decision #4's zero-tolerance
# flakiness policy with noise that isn't a real regression.
#
# No payment/supplier secrets required. Whenever APP_ENV=e2e
# (AppServiceProvider::register(), ADR-023 decision #6), both the
# supplier layer (SupplierAdapter -> FakeSupplierAdapter) and the
# payment layer (payment-gateway.chip -> FakePaymentGateway, ADR-022's
# 2026-09-01 addendum — CHIP's RSA-signed webhook can't be forged by the
# checkout spec) are bound to zero-network fakes. The checkout spec
# simulates CHIP's webhook by POSTing directly to /api/webhooks/chip.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR/backend"

export APP_ENV=e2e
# --no-ansi is required here, not cosmetic: Symfony Console detects the
# GITHUB_ACTIONS env var and force-decorates output with ANSI codes even
# though this command's stdout is being captured via $(), not a real TTY —
# `key:generate --show` wraps the key in a `<comment>` tag, so without
# --no-ansi the captured APP_KEY on CI silently contains escape-sequence
# bytes around the base64 key. Harmless until the first real encryption
# happens — surfaced as "Unsupported cipher or incorrect key length" the
# moment E2ESeeder writes Supplier.api_config (encrypted cast, ADR-046),
# not at key-generation time itself. Never reproduced locally: no
# GITHUB_ACTIONS env var, so Symfony never force-decorates a non-TTY pipe.
export APP_KEY="${APP_KEY:-$(php artisan key:generate --show --no-ansi)}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$ROOT_DIR/backend/database/e2e.sqlite"
export QUEUE_CONNECTION=database
export CACHE_STORE=database
# ADR-027's 2026-08-29 addendum, decision 18: CatalogController's
# packages cache is scoped to its own store (config('cache.
# catalog_packages_store')), defaulting to 'redis' — no Redis service
# exists in this E2E suite (same reasoning FakeSupplierAdapter/APP_ENV=e2e
# already avoids needing one for the supplier layer), so left unset here
# it would 500 on the storefront checkout spec's first catalog fetch.
# 'array' (not 'database', unlike CACHE_STORE above) since Laravel's
# database cache driver doesn't implement Cache::tags() at all — the
# exact gap this store was moved off of in the first place — while
# 'array' does, and needs no extra service; it persists correctly across
# requests here since this whole suite runs as one continuous `php
# artisan serve` process, not per-request-forked PHP-FPM workers.
export CATALOG_PACKAGES_CACHE_STORE=array
export SESSION_DRIVER=array
# ADR-060 PR-3: the storefront (served at :3001 here) sends its own host
# in `X-Storefront-Host`; `ResolveStorefrontBrand` treats a configured
# primary host as the primary brand with no `affiliate_domains` row. The
# Playwright storefront is `localhost` (see e2e/tests/constants.ts).
export STOREFRONT_PRIMARY_HOSTS=localhost

rm -f "$DB_DATABASE"
touch "$DB_DATABASE"

php artisan migrate:fresh --force
php artisan db:seed --class="Database\\Seeders\\E2ESeeder" --force

# Same queue-name split as production (ADR-020 decision #5) — one
# real queued job (FulfillOrderJob) must actually run for the checkout
# golden path to ever reach "Delivered".
trap 'kill 0' EXIT INT TERM
php artisan queue:work --queue=orders,price-sync,backups --tries=1 --sleep=1 &

# --no-reload is NOT optional here: Laravel's ServeCommand re-reads
# backend/.env for the actual served process and only passes through a
# small hardcoded env whitelist (APP_ENV, PATH, a few Herd/Xdebug vars —
# see ServeCommand::$passthroughVariables) unless --no-reload is given,
# which instead passes the FULL calling shell environment through
# unfiltered. Without it, this script's own DB_DATABASE/QUEUE_CONNECTION/
# etc. exports above are silently dropped and the served process falls
# back to whatever backend/.env has configured — found the hard way
# during this ADR's own verification, when the checkout/admin tests
# above turned out to be reading the real local dev database instead of
# e2e.sqlite.
php artisan serve --port=8003 --no-reload
