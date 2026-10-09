# PekanGame

A guest-checkout storefront for topping up game credits (MLBB and others),
with an admin panel for catalog/order/withdrawal management, a partner portal
serving two account types — **Affiliates** (whitelabel storefront owners with
their own branding, custom domain, and earnings ledger — ADR-060) and
**Resellers** (prepaid-wallet accounts that order via portal, REST API, or a
WhatsApp bot — ADR-072–076) — and a middleware layer that syncs prices and
validates player IDs against upstream suppliers.

Money moves through this system for real: checkout payments, ledger-tracked
balances, admin withdrawals, and store-credit vouchers. If you're new here,
read the ADR log (`docs/adr.md` index → `docs/adr/`) before changing anything
money-adjacent — most of the non-obvious decisions in the code are recorded there with the reasoning
behind them, not left implicit.

## Stack

| App | Tech | Purpose |
| --- | --- | --- |
| `backend/` | Laravel 13 (PHP 8.4), MySQL, Redis (queue + cache) | API for storefront, admin, partner portal, and middleware — checkout, orders, ledger, suppliers, payments |
| `admin/` | Next.js 16 + React 19 + Tailwind v4 | Internal admin panel — games/packages, orders, withdrawals, vouchers, affiliates, resellers, Price Sync Center, gallery |
| `storefront/` | Next.js 16 + React 19 + Tailwind v4 | Public storefront — catalog, guest checkout, order tracking. Also renders every Affiliate whitelabel brand per `Host`/custom domain (ADR-060) |
| `reseller/` | Next.js 16 + React 19 + Tailwind v4 | Partner portal — one app, two account types: Affiliate (earnings ledger, withdrawals, wholesale tier, storefront config, custom domain — ADR-058/059/060) and Reseller (prepaid wallet, API keys, bot — ADR-072–076) |
| `docs-site/` | Astro 7 + Starlight | Public Reseller API developer docs → `docs.pekangame.space` (ADR-084). OpenAPI spec is generated from the backend, not hand-written |

Auth is bearer-token (Laravel Sanctum) end to end — there's no session-cookie
auth and no CSRF surface between the frontends and the API (see ADR-009, ADR-019 and
`docs/foundation-security.md`).

## Getting started

Requires PHP 8.4+, Composer, Node 22 (what CI uses), and Docker (local Redis for the queue,
and the MySQL container used by concurrency tests — the app itself can run
against SQLite locally). `./scripts/dev.sh` starts everything below at once.
The frontends call `NEXT_PUBLIC_API_URL`, which locally is the Laravel Herd
site for `backend/`, so Herd must be running (`backend/AGENTS.md`, gotcha 6).

```bash
# Backend
cd backend
docker compose up -d redis   # Horizon needs Redis (ADR-048)
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer run dev     # API + Horizon + Reverb + Pulse + pail + vite, http://localhost:8000

# Admin (separate terminal)
cd admin
npm install
cp .env.local.example .env.local   # point at the backend API URL
npm run dev           # http://localhost:3000

# Storefront (separate terminal)
cd storefront
npm install
cp .env.local.example .env.local
npm run dev           # http://localhost:3001

# Partner portal (separate terminal)
cd reseller
npm install
cp ../admin/.env.local.example .env.local   # same vars as admin (dev.sh does this for you)
npm run dev           # http://localhost:3002
```

`composer run dev` starts `php artisan serve`, `horizon`, `reverb:start`,
`pulse:work`, `pail` (log tailing) and `vite` together — **the queue worker
matters**: dispatched jobs (order fulfillment, price sync) never run without
Horizon, and Horizon needs Redis. A bare `php artisan serve` or Laravel Herd on
its own does not start a worker for you. More local gotchas:
`backend/AGENTS.md`.

You'll need real credentials to exercise supplier/payment integrations
end-to-end — Digiflazz (the live supplier), `GAMEVION_BEARER_TOKEN`/`GAMEVION_API_KEY`
(set `GAMEVION_SANDBOX=true` to avoid touching production), and
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
cd e2e && npm test   # Playwright golden paths (ADR-023, 6 specs) — boots its own throwaway backend+DB, real Chromium
```

## Documentation

| Doc | What's in it |
| --- | --- |
| `docs/prd.md` | Full product spec (§1–13), the current-state headline (§14), a coarse per-feature tracker (§15) and the live backlog (§16) — check §15 first for "how much of X is actually built" |
| `docs/build-log.md` | Chronological record of what shipped and why, 2026-10-06 onward; older entries in `docs/build-log-archive.md` |
| `docs/adr.md` + `docs/adr/` | Architecture Decision Log — `adr.md` is the index (one row per decision, with its Standing), each decision is its own file in `docs/adr/`. Every non-trivial trade-off, numbered, with context/rationale, stress-tested before being accepted. The source of truth for *why* |
| `docs/foundation-security.md` | Actionable security checklist derived from the ADRs |
| `docs/legacy-reference-notes.md` | Notes from studying a prior/reference system — read as reference material, not as facts about this codebase |
| `AGENTS.md` / `CLAUDE.md` | Working conventions for AI coding agents (also useful as a quick orientation for a human joining the project) |
| `backend/AGENTS.md` | Laravel-specific conventions and local dev gotchas (`backend/CLAUDE.md` points to it) (money-as-integer-sen, ledger discipline, adapter pattern, queue-not-inline) |

## Current status

**Live in production with real money.** The backend runs on a Laravel
Forge–managed DigitalOcean droplet at `api.pekangame.space`; the four
frontends are on Vercel (storefront `pekangame.com`, `admin.pekangame.space`,
`reseller.pekangame.space`, `docs.pekangame.space`) — see
[ADR-066](docs/adr/ADR-066-production-deploy-via-laravel-forge.md)
and ADR-114. CHIP is the sole payment gateway and Digiflazz the live supplier;
reseller wallet orders are real customer orders. `docs/prd.md` §14 has the
current state, §15 the per-feature status and §16 the live backlog.
