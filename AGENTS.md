# Kedairuncitsoloz — Game Top-Up Reseller Platform

Guest-checkout storefront for reloading game credits (MLBB and others), backed
by an admin panel and a supplier/payment middleware layer. Money-critical:
checkout, ledger, withdrawals, and vouchers all move real value — treat every
change to those paths with the same care the existing code already does.

## Stack & Layout

| Path | What | Notes |
| --- | --- | --- |
| `backend/` | Laravel 13 API (PHP 8.3) | Sanctum bearer-token auth, MySQL, Redis-driver queue (Horizon, ADR-048) + `database`-driver cache (see ADR-014/019) |
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
- **Admin UI components migrate to PrimeReact (Tailwind mode) opportunistically, per ADR-038.**
  If you open a file in `admin/` that still uses a hand-rolled TailAdmin
  primitive (`Table`, `Modal`, `Dropdown`/`DropdownItem`, `Badge`, `Button`,
  or a basic form input from `components/form`) for work unrelated to this
  migration, swap it for the PrimeReact-Tailwind equivalent as part of that
  same change — don't leave it for a dedicated migration pass. Never force a
  migration on an already-tested, live screen just to swap its component
  library; the trigger is always "already touching this file for another
  reason." `RichTextEditor` is exempt (no PrimeReact equivalent exists). See
  `docs/prd.md`'s PrimeReact Migration Tracker for per-screen status.
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

## Branch Workflow (ADR-037)

- **Before editing any file for a build/fix task, run `git branch
  --show-current` first.** If it comes back `staging` or `main`, cut a
  `fix/*`/`feature/*` branch off `staging` (see below) before touching
  anything — don't edit first and branch afterward. This step has been
  skipped in practice even with this file loaded, so treat it as the actual
  first action of the task, not implied by the rules below it.
- **Never branch from `main`.** Every feature/fix branch is cut from
  `staging`, PRs back into `staging`, gets verified in the staging
  environment, and only then does `staging` merge into `main` for
  production. Both `staging` and `main` are protected, PR-only, with
  required CI checks.
- **One exception: `hotfix/*` branches may cut directly from `main`**, scoped
  strictly to P0 incidents (payment/ledger/checkout down) where waiting on
  the full staging cycle isn't acceptable. Immediately after a hotfix merges
  into `main`, merge `main` back into `staging` (a plain merge, not a
  cherry-pick) so `staging` never drifts behind.
- **Merge commits only (`--no-ff`), never squash** — for `staging`→`main`
  and the hotfix `main`→`staging` re-sync alike. Matches this repo's
  existing granular-commit history and keeps a money-critical system's
  changes traceable to the exact commit that shipped them.
- No extra review gate beyond CI passing green — this is a solo-founder
  workflow, not a multi-engineer team. `/code-review` is available at the
  founder's discretion, not a merge requirement.

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
cd backend && docker compose up -d redis  # ADR-048: QUEUE_CONNECTION=redis, composer run dev's horizon process needs this running first
cd backend && composer run dev        # serve + horizon + pail + vite, all together
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
actual queue worker running — `composer run dev` includes one (`php artisan
horizon`, since ADR-048); a bare `php artisan serve` (or Laravel Herd on its
own) does not. A stuck "Syncing…" state with nothing updating almost always
means the worker isn't running, not a frontend bug — see `docs/prd.md` §14's
2026-07-27 live-testing entry. A second, quieter cause of the same symptom:
`composer run dev` itself silently kills its own queue worker if
`backend/node_modules` was never installed (`npm install` inside `backend/`,
separate from `admin/`/`storefront/`'s own installs) — its `vite` step fails
and `concurrently --kill-others` tears down `horizon` with it, visible only
in the backend's own terminal output. See `docs/prd.md` §14's 2026-07-28
addendum. **Third cause, since ADR-048:** `horizon` itself needs
`backend/docker-compose.yml`'s `redis` service running (`QUEUE_CONNECTION`
moved off `database` onto `redis`, and Horizon has no `database`-driver
fallback) — if that container isn't up, `horizon`'s pane in `composer run
dev`'s output shows a connection-refused error, not a silent no-op, but it's
easy to miss in interleaved terminal output.

**Second known gotcha:** a new migration written during a session only runs
automatically against the *test* databases (sqlite `:memory:` for `php artisan
test`, the dockerized MySQL for the concurrency suite) — never against the
actual local dev database (`backend/database/database.sqlite` or whatever
Herd/`composer run dev` serves against). `php artisan test` passing green is
not proof the local dev DB has the new tables/columns. A `SQLSTATE[HY000]:
... no such table` (or "unknown column") error in the browser/Postman against
a locally-running backend almost always means this — run `php artisan
migrate:status` to confirm, then `php artisan migrate` — not a code bug. Any
session that adds a migration should run **plain `php artisan migrate`**
(never `migrate:fresh`) against the local dev DB before calling the feature
done, not just the test suites. `migrate:fresh` **drops every table** — the
local sqlite dev DB is gitignored with no backup, so a `migrate:fresh` there
permanently wipes any locally-set-up games/packages/test data. See
`docs/prd.md` §14's 2026-07-29 Blacklist/Fraud entry for the additive-migrate
gotcha and its 2026-08-31 ADR-061 PR-B entry for a `migrate:fresh` data-loss
incident.

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

**Fourth known gotcha:** capturing an `artisan` command's output via shell
command substitution (`` $(...) ``) is only safe with `--no-ansi`. Symfony
Console force-decorates output with ANSI color codes whenever it detects the
`GITHUB_ACTIONS` env var, even though the command's stdout is being piped
into a variable, not a real TTY — so the captured string silently contains
escape-sequence bytes on CI while looking completely clean in any local
shell (no `GITHUB_ACTIONS` var there). Found in `e2e/scripts/boot-backend.sh`
(`export APP_KEY="$(php artisan key:generate --show)"`, 2026-08-27): the
corrupted `APP_KEY` broke nothing until the first real encrypted write
(`Supplier.api_config`, ADR-046), which then surfaced only as Playwright's
generic "Process from config.webServer was not able to start" — see
`docs/prd.md` §14's 2026-08-27 entry for the full root-cause chain. Any
script that captures `artisan` output into a variable needs `--no-ansi`.
