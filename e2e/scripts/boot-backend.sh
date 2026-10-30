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
# Never use live credentials. backend/.env holds the real keys for local work and artisan still reads
# it, so a membership receipt here once went out through production Plunk to an example.com address.
# An empty value already set in the environment wins over .env. CI has none of these keys.
export PLUNK_API_KEY=""
export CHIP_SECRET_KEY=""
export CHIP_BRAND_ID=""
export GAMEVION_API_KEY=""
export GAMEVION_BEARER_TOKEN=""
export GEMINI_API_KEY=""
export SUPPLIER_PROXY_URL=""
export DB_CONNECTION=sqlite
export DB_DATABASE="$ROOT_DIR/backend/database/e2e.sqlite"
export QUEUE_CONNECTION=database
# The web server, queue worker and database cache share one sqlite file.
# Without a busy timeout a write that meets another's lock fails at once
# ("database is locked"), which turned a CI fulfilment into needs_review
# (2026-10-04, PR #345). WAL lets readers proceed during a write.
export DB_BUSY_TIMEOUT=5000
export DB_JOURNAL_MODE=wal
# The timeout alone did not hold (2026-10-06, PR #360, same symptom): a
# DEFERRED transaction that reads first and then writes after another
# connection committed fails at once in WAL. IMMEDIATE takes the write lock
# at BEGIN, so the busy timeout applies.
export DB_TRANSACTION_MODE=IMMEDIATE
# ADR-125 1b-ii: 'array', not 'database' — every admin game write flushes
# the tagged catalog index (`Cache::tags`), which the database driver
# doesn't implement, so the first spec that edits a game 500'd (and the
# storefront's game list already fell back on every read). Like the
# packages store below, it holds nothing across requests here, which only
# loosens rate limits in this throwaway suite; sessions are on `file`.
export CACHE_STORE=array
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
# ADR-121: the admin login is a cookie session, so it must survive between
# requests — `php artisan serve` boots the app per request, which empties
# the array driver every time.
export SESSION_DRIVER=file
# ADR-060 PR-3: the storefront (served at :3001 here) sends its own host
# in `X-Storefront-Host`; `ResolveStorefrontBrand` treats a configured
# primary host as the primary brand with no `affiliate_domains` row. The
# Playwright storefront is `localhost` (see e2e/tests/constants.ts).
export STOREFRONT_PRIMARY_HOSTS=localhost
# ADR-125 PR 2 (supplier-group-bot.spec.ts): a configured bot session so a
# supplier post is queued, not skipped, and a webhook secret so the spec can
# send signed group messages. Nothing reaches the network: the worker below
# never runs the `whatsapp` or `default` lanes those sends sit on, and the
# base URL points at a closed port.
export OPENWA_BASE_URL=http://127.0.0.1:9
export OPENWA_SESSION_ID=e2e-bot
export OPENWA_API_KEY=e2e-bot-key
export OPENWA_WEBHOOK_SECRET=e2e-openwa-secret

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
