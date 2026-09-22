# PekanGame — Game Top-Up Reseller Platform

> Repo directory, git remote, database name, and internal key prefixes
> still read `kedairuncitsoloz` / `topup-website` by deliberate choice
> (ADR-062 §5 + its addendum) — those are churn with no user-visible
> benefit. The brand everywhere a human looks is **PekanGame**.

Guest-checkout storefront for reloading game credits (MLBB and others), backed
by an admin panel and a supplier/payment middleware layer. Money-critical:
checkout, ledger, withdrawals, and vouchers all move real value — treat every
change to those paths with the same care the existing code already does.

## Stack & Layout

| Path | What | Notes |
| --- | --- | --- |
| `backend/` | Laravel 13 API (PHP 8.3) | Sanctum bearer-token auth, MySQL, Redis-driver queue (Horizon, ADR-048) + Redis-driver cache (ADR-077, reverses ADR-014/019's `database` driver) |
| `admin/` | Next.js 16 admin panel | Games, Orders, Withdrawals, Vouchers, Price Sync Center, Gallery, Affiliates, Resellers |
| `storefront/` | Next.js 16 customer storefront | Guest checkout only — no customer accounts (ADR-011). Also renders every **Affiliate** whitelabel brand, resolved per `Host` / custom domain (ADR-060) |
| `reseller/` | Next.js 16 partner portal | One app, two account types (ADR-072): **Affiliate** (whitelabel storefront owner — earnings ledger, withdrawals, wholesale tier, storefront config, custom domain) and **Reseller** (prepaid-wallet spend-only account — wallet top-up, API keys). Runs on `:3002` (ADR-059) |
| `docs-site/` | Astro 7 + Starlight — public Reseller API docs | The **4th frontend** (ADR-084). Deploys on Vercel → `docs.pekangame.space`; `/docs/api` on the backend 301s to it. Spec is generated (`php artisan scramble:export`), never hand-edited; CI drift-guards it |
| `docs/` | `prd.md` (§1–13 spec, §14 status headline, §15 feature tracker, §16 backlog), `adr.md` (decision log — has an ADR index at the top), `build-log.md` (the running chronological build record — moved out of §14 on 2026-09-11), `foundation-security.md`, `legacy-reference-notes.md` | Read `adr.md` before assuming *why* something is built a certain way — it's almost always a recorded, deliberate decision |

> **Terminology (ADR-072, 2026-09-04):** the old whitelabel "Reseller" was
> renamed **Affiliate**; "Reseller" now means a prepaid-wallet account with
> three order channels (portal, REST API — ADR-074, WhatsApp bot — ADR-075).
> ADR titles/bodies written before ADR-072 that say "Reseller" mean today's
> "Affiliate". See PRD §13 Glossary.

`admin/`, `storefront/`, `reseller/`, and `docs-site/` each carry their own
`CLAUDE.md`/`AGENTS.md` — `admin/` and `storefront/` are just the auto-generated
Next.js-version banner; `reseller/` and `docs-site/` add their own conventions
(`docs-site/CLAUDE.md` is a symlink to its `AGENTS.md`). This file covers the
whole repo; `backend/CLAUDE.md` adds Laravel-specific conventions on top of it.

## Task Lifecycle (build / fix work)

Not every request is a build task — a question, a doc tweak, or a genuine
one-liner skips most of this. For anything that changes behaviour:

1. **Orient first.** Before designing anything, check whether it already exists
   in the docs: scan the `docs/adr.md` index (is there an Accepted/RESERVED/
   parked ADR for this?), `docs/prd.md` §15 (already built?) and §16 (already
   on the backlog, or parked with a recorded reason?). Build on an existing ADR
   rather than writing a duplicate; if something was parked, surface *why*
   before re-opening it. `docs/build-log.md` (grep by keyword) has the "was
   this tried before" detail.
2. **Decide + grill.** A non-trivial design or trade-off gets a numbered
   `docs/adr.md` entry (Context → Decision → Rationale → Consequence),
   stress-tested with `/mattpocock-skills:grilling` before it's marked Accepted.
   After the grill, give the founder a plain-language recap of the whole plan
   before writing any code.
3. **Branch before the first edit.** `git branch --show-current`; if it comes
   back `staging` or `main`, cut a `feature/*` or `fix/*` branch off `staging`
   *now* (see Branch Workflow below). This gets skipped in practice — treat it
   as the literal first action of the task.
4. **Build it** — test-first (red → green) for money-critical logic; tests live
   in `backend/tests`.
5. **Migrate the local dev DB** — plain `php artisan migrate` (never
   `migrate:fresh`). `php artisan test` passing is *not* proof the local DB has
   the new tables/columns — see the migration gotcha under Build & Test.
6. **Verify** — `php artisan test` (+ the concurrency suite if locking changed);
   `tsc` / `lint` / `build` for any frontend touched; a real browser or `curl`
   check for anything user-facing, not just a green suite.
7. **Update the docs** — append the "what shipped, why, gotchas" note to
   `docs/build-log.md`; move items across `docs/prd.md` §15 / §16; add a new
   `docs/adr.md` entry (and an index row) for any new decision.
8. **Commit** (granular, `--no-ff` history), **push**, **open a PR into
   `staging`**. CI green is the only merge gate. A `staging`→`main` release is a
   separate, deliberate step — only when the founder asks for it.

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
- **Admin/reseller UI is PrimeReact (Tailwind mode), per ADR-038.** The
  opportunistic migration off hand-rolled TailAdmin primitives (`Table`,
  `Modal`, `Dropdown`, `Badge`, `Button`, `components/form` inputs) **completed
  2026-08-29** — those source files are deleted. Build new screens with the
  PrimeReact-Tailwind components and the shared `globals.css` design tokens.
  `RichTextEditor` is the one hand-rolled primitive that stays (no PrimeReact
  equivalent). Screen-by-screen migration history is in `docs/build-log.md`.
- **Money is never trusted from the client.** Price, cost, and profit are
  always computed server-side from stored `Package`/`Game` data at the moment
  of use — see ORD-9 in `docs/prd.md` and `PricingService`. If you find
  yourself accepting a monetary value in a request payload, stop and check
  whether it should be computed instead.
- **Keep the docs current when something ships.** `docs/build-log.md` is the
  running chronological record (what shipped, when, why, gotchas hit) — append
  to it. `docs/prd.md` §15 is the coarse status-by-feature-area tracker and §16
  is the live backlog — move items across as they land. `docs/adr.md` gets a new
  numbered entry for any non-trivial decision. Don't let any of them drift from
  what's actually true — verify against production, not memory.
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
  `staging`, PRs back into `staging`, gets verified there (CI green — and,
  for a frontend change, the PR's Vercel Preview deploy; there is no
  separately-deployed staging server), and only then does `staging` merge
  into `main`. **A push to `main` auto-deploys the backend to production**
  via the CI `deploy` job (POSTs `FORGE_DEPLOY_HOOK`, gated on all test jobs
  green — ADR-066); the four frontends (`admin/`, `storefront/`, `reseller/`,
  `docs-site/`) auto-deploy from `main` on Vercel.
  Both `staging` and `main` are protected, PR-only, with required CI checks.
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
# All four at once (backend + admin + storefront + reseller, labeled/interleaved output, single Ctrl+C stops all)
# Also starts local Redis and seeds/installs reseller/ on first run.
./scripts/dev.sh

# Backend
cd backend && docker compose up -d redis  # ADR-048: QUEUE_CONNECTION=redis, composer run dev's horizon process needs this running first
cd backend && composer run dev        # serve + horizon + pail + vite, all together
cd backend && php artisan test        # fast suite (sqlite, no Docker)
cd backend && docker compose up -d && php artisan test -c phpunit.concurrency.xml  # concurrency/locking proofs, needs real MySQL

# Admin / Storefront / Reseller portal (run separately, each has its own dev server)
cd admin && npm run dev               # :3000 — or: npm run build && npm run lint
cd storefront && npm run dev          # :3001
cd reseller && npm run dev            # :3002 (ADR-059; see reseller/AGENTS.md)

# E2E (ADR-023) — the golden-path tests (checkout->payment->order status;
# admin login->Resend Delivery; admin login->Issue Voucher; admin->Mark
# Delivered), Chromium only. Boots its own throwaway backend+DB — never
# touches the local dev DB. Needs NO external secret: both the supplier
# layer (Gamevion) and the payment layer (CHIP) are bound to zero-network
# fakes whenever APP_ENV=e2e (ADR-022's 2026-09-01 addendum put payment
# on the same footing). Wired into CI (.github/workflows/ci.yml's
# `playwright` job) — this is for running it locally.
cd e2e && npm test
```

**Known gotcha:** a queued job (Price Sync, order fulfillment/resend) needs an
actual queue worker running — `composer run dev` includes one (`php artisan
horizon`, since ADR-048); a bare `php artisan serve` (or Laravel Herd on its
own) does not. A stuck "Syncing…" state with nothing updating almost always
means the worker isn't running, not a frontend bug — see `docs/build-log.md`'s
2026-07-27 live-testing entry. A second, quieter cause of the same symptom:
`composer run dev` itself silently kills its own queue worker if
`backend/node_modules` was never installed (`npm install` inside `backend/`,
separate from `admin/`/`storefront/`'s own installs) — its `vite` step fails
and `concurrently --kill-others` tears down `horizon` with it, visible only
in the backend's own terminal output. See `docs/build-log.md`'s 2026-07-28
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
`docs/build-log.md`'s 2026-07-29 Blacklist/Fraud entry for the additive-migrate
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
`docs/build-log.md`'s 2026-08-27 entry for the full root-cause chain. Any
script that captures `artisan` output into a variable needs `--no-ansi`.

**Fifth known gotcha:** each of `admin/`, `storefront/`, `reseller/`,
`docs-site/` has its own `vercel.json` with an `ignoreCommand` (`git diff
--quiet HEAD^ HEAD .`) that skips that app's Vercel build entirely when
nothing under its own directory changed — added 2026-09-22 after PR #269
found every push was rebuilding all 4 frontends regardless of relevance. A
merged PR that only shows 3 (or fewer) of the 4 Vercel deployments actually
build — the rest show `Canceled` / "Ignored Build Step command returned exit
code 0" — is this working as intended, not a broken deploy. If a real change
to one of these apps ever needs to force a rebuild without touching that
app's own directory (e.g. a shared config file moves outside it), the
`ignoreCommand` needs updating too, or that app will silently stay stale.
