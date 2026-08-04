# Kedairuncitsoloz — Game Top-Up Reseller Platform

Guest-checkout storefront for reloading game credits (MLBB and others), backed
by an admin panel and a supplier/payment middleware layer. Money-critical:
checkout, ledger, withdrawals, and vouchers all move real value — treat every
change to those paths with the same care the existing code already does.

## Stack & Layout

| Path | What | Notes |
| --- | --- | --- |
| `backend/` | Laravel 13 API (PHP 8.3) | Sanctum bearer-token auth, MySQL, `database`-driver queue/cache (see ADR-014/019) |
| `admin/` | Next.js 16 admin panel | Games, Orders, Withdrawals, Vouchers, Price Sync Center, Gallery |
| `storefront/` | Next.js 16 customer storefront | Guest checkout only — no customer accounts (ADR-011) |
| `docs/` | `prd.md` (spec + build-status log), `adr.md` (decision log), `foundation-security.md`, `legacy-reference-notes.md` | Read `adr.md` before assuming *why* something is built a certain way — it's almost always a recorded, deliberate decision |

`admin/` and `storefront/` each carry their own `CLAUDE.md`/`AGENTS.md` — a
Next.js-version warning specific to that app. This file covers the whole repo;
`backend/CLAUDE.md` adds Laravel-specific conventions on top of it.

## Working Conventions

- **Record decisions, don't just make them.** Every non-trivial architecture
  or trade-off decision gets its own numbered entry in `docs/adr.md`
  (Context → Decision → Rationale → Consequence-to-track), stress-tested with
  `/mattpocock-skills:grilling` before being marked Accepted — this is the
  project's actual established practice (see ADR-014, ADR-015), not an
  aspiration. Don't skip straight to code for a decision that will confuse a
  future reader (or future AI session) without knowing *why*.
- **Deep modules, stable seams.** `SupplierAdapter`, `PaymentGateway`, and
  `OrderFulfillmentService` are the project's deliberate seams — extend an
  *implementation* behind them (new adapter, new retry policy) rather than
  reshaping the interface itself. Use `/mattpocock-skills:codebase-design`
  when a change seems to want a new seam.
- **New business terms get pinned before they leak into code** as ad-hoc
  naming — use `/mattpocock-skills:domain-modeling` (see PRD §13 Glossary for
  the terms already pinned: ledger, reference_number vs order_number, etc.).
- **Money is never trusted from the client.** Price, cost, and profit are
  always computed server-side from stored `Package`/`Game` data at the moment
  of use — see ORD-9 in `docs/prd.md` and `PricingService`. If you find
  yourself accepting a monetary value in a request payload, stop and check
  whether it should be computed instead.
- **Keep `docs/prd.md` §14/§15 current.** §14 is a chronological build log
  (what shipped, when, why); §15 is the coarse status-by-feature-area
  tracker. Update both when a tracked item ships or a new gap is found —
  don't let the docs drift from what's actually true, per the doc's own
  standing instruction.
- Do what has been asked; nothing more, nothing less.
- Never create files unless necessary — prefer editing existing files. Never
  create documentation files unless explicitly requested.
- Never save working files or tests to root — use `backend/tests`,
  `docs/`, or the relevant app's own structure.
- Always read a file before editing it. Never commit secrets, credentials,
  or `.env` files.
- Keep files under ~500 lines where reasonable. Validate input at system
  boundaries (an `Http\Requests\*` FormRequest for every mutating backend
  route; Zod/equivalent at the frontend's API boundary).
- Never add a `Co-Authored-By` trailer to commits unless this project's
  `.claude/settings.json` has `attribution.commit` set. A tool's default
  commit-message template may suggest one — ignore it unless that setting is
  present.

## Multi-Agent Work

Use the standard `Agent`/`Task` tooling directly — spawn a background agent
(or a `fork` if it should inherit conversation context) for research or
multi-step work that would otherwise flood the main conversation with raw
output. Reach for this on genuinely multi-file, cross-cutting work (new
features, audits, refactors spanning several services); a single-file edit
or a one-line fix doesn't need it.

## Build & Test

```bash
# All three at once (backend + admin + storefront, labeled/interleaved output, single Ctrl+C stops all)
./scripts/dev.sh

# Backend
cd backend && composer run dev        # serve + queue:listen + pail + vite, all together
cd backend && php artisan test        # fast suite (sqlite, no Docker)
cd backend && docker compose up -d && php artisan test -c phpunit.concurrency.xml  # concurrency/locking proofs, needs real MySQL

# Admin / Storefront (run separately, each has its own dev server)
cd admin && npm run dev               # or: npm run build && npm run lint
cd storefront && npm run dev

# E2E (ADR-023) — the 3 golden-path tests (checkout->payment->order status;
# admin login->Resend Delivery; admin login->Issue Voucher), Chromium only.
# Boots its own throwaway backend+DB — never touches the local dev DB.
# Storefront checkout needs a real Xendit test-mode key: export
# XENDIT_SECRET_KEY before running, or that one spec fails at the real
# Xendit API call while the 2 admin specs still pass. Wired into CI
# (.github/workflows/ci.yml's `playwright` job) — this is for running it
# locally.
cd e2e && npm test
```

**Known gotcha:** a queued job (Price Sync, order fulfillment/resend) needs an
actual queue worker running — `composer run dev` includes one; a bare
`php artisan serve` (or Laravel Herd on its own) does not. A stuck "Syncing…"
state with nothing updating almost always means the worker isn't running, not
a frontend bug — see `docs/prd.md` §14's 2026-07-27 live-testing entry. A
second, quieter cause of the same symptom: `composer run dev` itself silently
kills its own queue worker if `backend/node_modules` was never installed
(`npm install` inside `backend/`, separate from `admin/`/`storefront/`'s own
installs) — its `vite` step fails and `concurrently --kill-others` tears down
`queue:listen` with it, visible only in the backend's own terminal output.
See `docs/prd.md` §14's 2026-07-28 addendum.

**Second known gotcha:** a new migration written during a session only runs
automatically against the *test* databases (sqlite `:memory:` for `php artisan
test`, the dockerized MySQL for the concurrency suite) — never against the
actual local dev database (`backend/database/database.sqlite` or whatever
Herd/`composer run dev` serves against). `php artisan test` passing green is
not proof the local dev DB has the new tables/columns. A `SQLSTATE[HY000]:
... no such table` (or "unknown column") error in the browser/Postman against
a locally-running backend almost always means this — run `php artisan
migrate:status` to confirm, then `php artisan migrate` — not a code bug. Any
session that adds a migration should run `php artisan migrate` against the
local dev DB before calling the feature done, not just the test suites — see
`docs/prd.md` §14's 2026-07-29 Blacklist/Fraud entry for a live instance of
this exact gotcha.

**Third known gotcha:** `php artisan serve` re-reads `backend/.env` for the
process it actually spawns and only passes through a small Laravel-hardcoded
list of env vars from the calling shell (`APP_ENV`, `PATH`, a few Herd/Xdebug
vars — `ServeCommand::$passthroughVariables`) — every other exported var
(`DB_DATABASE`, `QUEUE_CONNECTION`, etc.) is silently dropped and falls back
to whatever `.env` already has, *unless* `--no-reload` is passed, which
passes the full calling-shell environment through unfiltered instead. Found
building `e2e/scripts/boot-backend.sh` (ADR-023): without `--no-reload`, a
script that exports `DB_DATABASE` to point `serve` at a throwaway DB silently
serves requests against the real local dev DB instead, with no error — the
served process just quietly uses `.env`'s own value. Any script that boots
`php artisan serve` against env vars set outside `.env` needs `--no-reload`.
