# PekanGame

A guest-checkout storefront for topping up game credits (MLBB and others),
with an admin panel for catalog/order/withdrawal management, a reseller portal
(resellers get their own branded storefront and an earnings ledger), and a
middleware layer that syncs prices and validates player IDs against upstream
suppliers.

Money moves through this system for real: checkout payments, ledger-tracked
balances, admin withdrawals, and store-credit vouchers. If you're new here,
read `docs/adr.md` before changing anything money-adjacent — most of the
non-obvious decisions in the code are recorded there with the reasoning
behind them, not left implicit.

## Stack

| App | Tech | Purpose |
| --- | --- | --- |
| `backend/` | Laravel 13 (PHP 8.3), MySQL | API for storefront, admin, reseller portal, and middleware — checkout, orders, ledger, suppliers, payments |
| `admin/` | Next.js 16 + React 19 + Tailwind v4 | Internal admin panel — games/packages, orders, withdrawals, vouchers, resellers, Price Sync Center, gallery |
| `storefront/` | Next.js 16 + React 19 + Tailwind v4 | Public storefront — catalog, guest checkout, order tracking |
| `reseller/` | Next.js 16 + React 19 + Tailwind v4 | Reseller portal — a reseller's own orders, earnings ledger, withdrawals, wholesale-tier subscription (ADR-058/059) |

Auth is bearer-token (Laravel Sanctum) end to end — there's no session-cookie
auth and no CSRF surface between the frontends and the API (see ADR-009 and
`docs/adr.md`'s security notes).

## Getting started

Requires PHP 8.3+, Composer, Node 20+, and Docker (for the MySQL container
used by concurrency tests — the app itself can run against SQLite locally).

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer run dev     # serves the API + queue worker + vite together, http://localhost:8000

# Admin (separate terminal)
cd admin
npm install
cp .env.local.example .env.local   # point at the backend API URL
npm run dev           # http://localhost:3000

# Storefront (separate terminal)
cd storefront
npm install
cp .env.local.example .env.local
npm run dev           # http://localhost:3001 (or whatever port is free)
```

`composer run dev` starts `php artisan serve`, `queue:listen`, `pail`
(log tailing), and `vite` together — **the queue worker matters**: dispatched
jobs (order fulfillment, price sync) sit unprocessed in the `jobs` table
without one running. A bare `php artisan serve` or Laravel Herd on its own
does not start a worker for you.

You'll need real credentials to exercise supplier/payment integrations
end-to-end — `GAMEVION_BEARER_TOKEN`/`GAMEVION_API_KEY` (set
`GAMEVION_SANDBOX=true` to avoid touching production), and
`CHIP_SECRET_KEY`/`CHIP_BRAND_ID` for the payment gateway (CHIP is the sole
gateway since ADR-022's 2026-09-01 addendum — Xendit was removed). Without
them, everything up to the supplier/payment call still works against the
local DB.

## Testing

```bash
cd backend
php artisan test                                              # fast suite, sqlite, no Docker needed
docker compose up -d && php artisan test -c phpunit.concurrency.xml   # locking/concurrency proofs, needs real MySQL
php artisan app:chip-smoke-test        # hits the real CHIP API
php artisan app:gamevion-smoke-test    # hits the real Gamevion sandbox
php artisan app:digiflazz-smoke-test   # hits the real Digiflazz API
```

```bash
cd admin && npm run lint && npx tsc --noEmit
cd storefront && npm run lint && npx tsc --noEmit
cd reseller && npm run lint && npx tsc --noEmit
```

```bash
cd e2e && npm test   # Playwright, 4 golden paths (ADR-023) — boots its own throwaway backend+DB, real Chromium
```

## Documentation

| Doc | What's in it |
| --- | --- |
| `docs/prd.md` | Full product spec (§1–13), plus a running build-status log (§14) and a coarse per-feature tracker (§15) — check §15 first for "how much of X is actually built" |
| `docs/adr.md` | Architecture Decision Log — every non-trivial trade-off, numbered, with context/rationale, stress-tested before being accepted. The source of truth for *why* |
| `docs/foundation-security.md` | Actionable security checklist derived from the ADRs |
| `docs/legacy-reference-notes.md` | Notes from studying a prior/reference system — read as reference material, not as facts about this codebase |
| `AGENTS.md` / `CLAUDE.md` | Working conventions for AI coding agents (also useful as a quick orientation for a human joining the project) |
| `backend/CLAUDE.md` | Laravel-specific conventions (money-as-integer-sen, ledger discipline, adapter pattern, queue-not-inline) |

## Current status

**Deployed, pre-commercial-launch — but it has taken its first real money.**
The backend is live on a Laravel Forge–managed DigitalOcean droplet at
`api.pekangame.space`, the three frontends are on Vercel (`pekangame.space`,
`admin.pekangame.space`, `reseller.pekangame.space`) — see [ADR-066](docs/adr.md#adr-066-production-deploy-via-laravel-forge--reverses-adr-020s-docker-compose-containerisation) —
and as of 2026-09-03 CHIP FPX is **live** (live keys, `fpx` channel active): one
real order (`PG-PYAYMRYNUYV0`, RM1.94) has been paid end-to-end through the CHIP
hosted page + `success_callback` webhook. Not yet open for real customers: the
catalogue is one placeholder game and no supplier account is funded, so orders
can be paid but not delivered. `docs/prd.md` §14 has the running deploy log;
§15 (MVP Scope Tracker) has what's built vs. outstanding, and its "NEXT SESSION"
pointer has the launch blockers.
