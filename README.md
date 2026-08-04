# Kedairuncitsoloz

A guest-checkout storefront for topping up game credits (MLBB and others),
with an admin panel for catalog/order/withdrawal management and a middleware
layer that syncs prices and validates player IDs against upstream suppliers.

Money moves through this system for real: checkout payments, ledger-tracked
balances, admin withdrawals, and store-credit vouchers. If you're new here,
read `docs/adr.md` before changing anything money-adjacent — most of the
non-obvious decisions in the code are recorded there with the reasoning
behind them, not left implicit.

## Stack

| App | Tech | Purpose |
| --- | --- | --- |
| `backend/` | Laravel 13 (PHP 8.3), MySQL | API for storefront, admin, and middleware — checkout, orders, ledger, suppliers, payments |
| `admin/` | Next.js 16 + React 19 + Tailwind v4 | Internal admin panel — games/packages, orders, withdrawals, vouchers, Price Sync Center, gallery |
| `storefront/` | Next.js 16 + React 19 + Tailwind v4 | Public storefront — catalog, guest checkout, order tracking |

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
`GAMEVION_SANDBOX=true` to avoid touching production) and
`XENDIT_SECRET_KEY`/`XENDIT_WEBHOOK_TOKEN`. Without them, everything up to
the supplier/payment call still works against the local DB.

## Testing

```bash
cd backend
php artisan test                                              # fast suite, sqlite, no Docker needed
docker compose up -d && php artisan test -c phpunit.concurrency.xml   # locking/concurrency proofs, needs real MySQL
php artisan app:xendit-smoke-test      # hits the real Xendit sandbox
php artisan app:gamevion-smoke-test    # hits the real Gamevion sandbox
```

```bash
cd admin && npm run lint && npx tsc --noEmit
cd storefront && npm run lint && npx tsc --noEmit
```

```bash
cd e2e && npm test   # Playwright, 3 golden paths (ADR-023) — boots its own throwaway backend+DB, real Chromium
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

Pre-launch — the project currently runs on local development environments
only (no production deployment yet; see ADR-010 and the "no production infra"
notes in `docs/prd.md` §14). `docs/prd.md` §15 (MVP Scope Tracker) has the
up-to-date picture of what's built vs. outstanding per feature area.
