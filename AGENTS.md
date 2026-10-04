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
| `backend/` | Laravel 13 API (PHP 8.4) | Sanctum bearer-token auth, MySQL, Redis queue (Horizon, ADR-048) + Redis cache (ADR-077). Own `AGENTS.md`: Laravel conventions + local-dev gotchas |
| `admin/` | Next.js 16 admin panel | Games, Orders, Withdrawals, Vouchers, Price Sync Center, Gallery, Affiliates, Resellers |
| `storefront/` | Next.js 16 customer storefront | Guest checkout only — no customer accounts (ADR-011). Also renders every **Affiliate** whitelabel brand, resolved per `Host` / custom domain (ADR-060) |
| `reseller/` | Next.js 16 partner portal | One app, two account types (ADR-072): **Affiliate** (whitelabel storefront owner — earnings ledger, withdrawals, wholesale tier, storefront config, custom domain) and **Reseller** (prepaid-wallet spend-only account — wallet top-up, API keys). Runs on `:3002` (ADR-059) |
| `docs-site/` | Astro 7 + Starlight — public Reseller API docs | The **4th frontend** (ADR-084), `docs.pekangame.space`. Spec is generated (`php artisan scramble:export`), never hand-edited; CI drift-guards it |
| `docs/` | `prd.md` (§1–13 spec, §14 status headline, §15 feature tracker, §16 backlog), `adr.md` (decision log, index at the top), `build-log.md` (chronological record; older entries in `build-log-archive.md`), `foundation-security.md`, `legacy-reference-notes.md` | Read `adr.md` before assuming *why* something is built a certain way — it's almost always a recorded, deliberate decision |

> **Terminology (ADR-072):** the old whitelabel "Reseller" was renamed
> **Affiliate**; "Reseller" now means a prepaid-wallet account with three order
> channels (portal, REST API — ADR-074, WhatsApp bot — ADR-075). ADR text
> written before ADR-072 that says "Reseller" means today's "Affiliate". See
> PRD §13 Glossary.

`admin/`, `storefront/`, `reseller/`, `docs-site/` each carry their own
`CLAUDE.md`/`AGENTS.md` (`admin/` and `storefront/` are just the Next.js-version
banner). This file covers the whole repo.

## Which steps apply to which request

| Request | What to do |
| --- | --- |
| Question, explanation, audit, "check" / "semak" / "jangan ubah apa-apa" | Read and report only. No edits, branches, or commits until the founder asks for changes after seeing the report. |
| Design only (new ADR or addendum, build later) | Lifecycle steps 1–3 (branch `docs/*`), 7 (ADR + index row + a PRD §16 "Buildable when triggered" line), 8. |
| Build / fix | The full lifecycle below. |
| Anything that writes to production (data patch, `.env`, DB users, infra) | **Production Access** below, on top of whatever else applies. |

## Task Lifecycle (build / fix work)

1. **Orient first.** Check whether it already exists: the `docs/adr.md` index
   (Accepted / RESERVED / parked?), `docs/prd.md` §15 (already built?) and §16
   (on the backlog, or parked with a recorded reason?). Build on an existing
   ADR rather than writing a duplicate; if something was parked, surface *why*
   before re-opening it. Grep `docs/build-log.md` + `docs/build-log-archive.md`
   for "was this tried before". A doc or memory that says "open", "live", or
   "released" is a claim from when it was written — re-verify it against code,
   git, or production before building on it.
2. **Decide + grill.** A non-trivial design or trade-off gets a numbered
   `docs/adr.md` entry (Context → Decision → Rationale → Consequence to track),
   stress-tested with `/mattpocock-skills:grilling` before it's marked Accepted.
   Revising a recorded decision = addendum on that ADR; filling an
   undocumented gap = new ADR.
   **For a money-critical ADR (anything touching order price, profit, ledger,
   voucher, wallet, or budget), the grill alone is not enough — run a
   code-trace pass against real code before marking Accepted:**
   - Every "same as X" / "mirrors X" claim: open X and confirm both what it
     does *and* the precondition that makes it safe (e.g. `Voucher`'s
     log-and-proceed redeem race is safe only because one customer owns it —
     a public code breaks that).
   - Every money column the design reads or writes: list **all** existing
     writers and readers (grep), including other ADRs' recompute paths
     (resend ADR-105, combo residual ADR-107, real-cost ADR-111).
   - Every margin/safety claim: check it per pricing basis (Standard, Member,
     Affiliate tier, ResellerWallet), not just Standard.
   ADR-118 passed four grill rounds plus a stress test and still had four
   silent-wrong-money gaps that only this pass found (see its review addendum).
   Before "fix now or grill first?", pull real production scale (row counts,
   usage) rather than answering from code alone. After the grill, give the
   founder a plain-language recap of the whole plan before writing any code.
3. **Branch before the first edit — the literal first action.**
   `git branch --show-current`; if it says `staging` or `main`, cut a
   `feature/*`, `fix/*`, or `docs/*` branch off an up-to-date `staging` now.
   This step has been skipped in practice even with this file loaded.
4. **Build it** — test-first (red → green) for money-critical logic; tests live
   in `backend/tests`.
5. **Migrate the local dev DB** — plain `php artisan migrate`, never
   `migrate:fresh` (it wipes the unbacked-up dev DB). A green `php artisan test`
   is not proof the dev DB has the new columns — `backend/AGENTS.md`, gotcha 2.
6. **Verify** — `php artisan test` (+ the concurrency suite if locking
   changed); `tsc` / `lint` / `build` for any frontend touched; a real browser
   or `curl` check for anything user-facing, not just a green suite. A visual
   claim ("matches the design", "no contrast issue") needs a live
   screenshot + `getComputedStyle`, not code reading.
7. **Update the docs** — every one that the change makes untrue:
   - `docs/build-log.md`: append what shipped, why, gotchas hit.
   - `docs/prd.md` §15: the feature-area row. §16: close, move, or add items —
     including a line for any design-only ADR/addendum not yet built.
   - `docs/prd.md` §14: the headline, whenever a status fact in it changed
     (what's released, which supplier is live, who the customers are).
   - `docs/adr.md`: new entry or addendum, plus its index row.
   Docs record verified facts — check against production, not memory.
8. **Commit** (granular), **push**, **open a PR into `staging`**. CI green is
   the only merge gate. When CI fails, read the actual PR page
   (`gh pr view <n> --json mergeable,statusCheckRollup`) before theorizing —
   a "CI problem" has been a plain merge conflict before. For stacked PRs,
   retarget every downstream PR to its final base *before* merging a parent
   with `--delete-branch`.

## Release & Production State

- **What's live = `origin/main`.** Before stating release or production
  status, `git fetch` and diff `origin/main..origin/staging`. Local branch refs
  go stale silently, and a doc or memory "LIVE PROD" label can be wrong.
- A push to `main` auto-deploys the backend (CI `deploy` job → Forge hook,
  gated on all test jobs, ADR-066); the four frontends auto-deploy from `main`
  on Vercel. A `staging`→`main` release happens only when the founder asks.
- A fix merged to `staging` is **not** live. If a bug was patched in prod data
  but its code fix is still staging-only, the bug can recur — say so plainly
  (example: ADR-028's 2026-10-02 bulk-markup combo fix).

## Production Access

- **Read-only is fine and encouraged** for verifying claims: SSH to the Forge
  box, `SELECT` queries, logs, Horizon. See ADR-114 for the box layout
  (`.env` lives at the site root under Forge's zero-downtime `current/`).
- **Any write to production** (data remediation, `.env`, DB users/grants,
  destroying or resizing infra, OpenWA config) needs: the exact command, what
  it touches and its blast radius, and the founder's go-ahead *before* running
  it. Afterwards, record what ran plus before/after evidence in
  `docs/build-log.md`.
- Credential, `CREATE USER` / `GRANT`, and destroy-confirmation actions: hand
  the founder the exact command to run themselves.
- Prefer a code fix released through `staging`→`main` over a hand-run data
  patch. When a hand patch is unavoidable, make it idempotent and verify it
  with a second run that changes nothing.

## Working Conventions

- **Deep modules, stable seams.** `SupplierAdapter`, `PaymentGateway`, and
  `OrderFulfillmentService` are the deliberate seams — extend an
  *implementation* behind them rather than reshaping the interface. Use
  `/mattpocock-skills:codebase-design` when a change seems to want a new seam.
- **New business terms get pinned before they leak into code** — use
  `/mattpocock-skills:domain-modeling` (PRD §13 Glossary has the pinned terms).
- **Money is never trusted from the client.** Price, cost, and profit are
  computed server-side from stored `Package`/`Game` data at the moment of use
  (ORD-9, `PricingService`). A monetary value in a request payload is a red
  flag — check whether it should be computed instead.
- **Platform cost structure is private.** An Affiliate or Reseller surface
  (portal, API, bot, docs-site) never shows a tier's `markup_percent` or the
  platform's margin — only the tier name and their own fee/markup.
- **Supplier-integration config lives under `/middleware`, not `/admin`**,
  even when it sits on a model the admin panel also edits.
- **Admin/reseller UI is PrimeReact (Tailwind mode), ADR-038** — build new
  screens with the PrimeReact-Tailwind components and the shared `globals.css`
  design tokens. `RichTextEditor` is the one hand-rolled primitive kept.
- **Before calling storefront copy "hardcoded", grep for it** — much of it is
  admin-configured content.
- Do what has been asked; nothing more, nothing less. If a more robust fix
  exists than the minimal one, *propose* it — don't silently build it.
- Never create files unless necessary — prefer editing existing files. Never
  create documentation files unless explicitly requested. Never save working
  files or tests to the repo root.
- Always read a file before editing it. Never commit secrets, credentials,
  or `.env` files.
- Keep files under ~500 lines where reasonable. Validate input at system
  boundaries (a FormRequest for every mutating backend route; Zod/equivalent
  at the frontend's API boundary).

## Branch Workflow (ADR-037)

- **Never branch from `main`.** Every feature/fix/docs branch is cut from
  `staging` and PRs back into `staging`, where it is verified (CI green, plus
  the PR's Vercel Preview for a frontend change; there is no separate staging
  server). Both `staging` and `main` are protected, PR-only, with required CI.
- **One exception: `hotfix/*` may cut from `main`**, strictly for P0 incidents
  (payment/ledger/checkout down). Right after it merges, merge `main` back
  into `staging` (a plain merge, not a cherry-pick).
- **Merge commits only (`--no-ff`), never squash** — keeps a money-critical
  system's changes traceable to the exact commit that shipped them.
- No review gate beyond green CI (solo-founder workflow). `/code-review` is at
  the founder's discretion.
- Each frontend's `vercel.json` has an `ignoreCommand` that skips its build
  when nothing under its own directory changed. A PR showing fewer than 4
  Vercel builds (the rest `Canceled` / "Ignored Build Step") is working as
  intended. If a shared file outside an app's directory ever needs to trigger
  that app's rebuild, update its `ignoreCommand` too.

## Multi-Agent Work

- Spawn a background agent (or a `fork` when it should inherit conversation
  context) for research or multi-step work that would otherwise flood the main
  conversation. Use it for genuinely cross-cutting work (features, audits,
  multi-service refactors), not single-file edits.
- **Money-critical review: one fork per layer, in parallel** — checkout and
  pricing; ledger, fulfillment, and compensation; downstream readers (reports,
  analytics, accounting, admin/portal UI, customer messages). This is the
  pattern that found ADR-118's gaps. Verify the top findings yourself before
  reporting them.
- A non-worktree fork reads the **live** checkout. Don't `git checkout`,
  merge, or reset while one is running. Read every fork's report for actions
  beyond the scope it was given.

## Build & Test

```bash
# All four at once (backend + admin + storefront + reseller); also starts local Redis.
./scripts/dev.sh

# Backend
cd backend && docker compose up -d redis  # Horizon needs Redis (ADR-048)
cd backend && composer run dev            # serve + horizon + pail + vite
cd backend && php artisan test            # fast suite (sqlite, no Docker)
cd backend && docker compose up -d && php artisan test -c phpunit.concurrency.xml  # locking proofs, real MySQL

# Frontends (each its own dev server)
cd admin && npm run dev               # :3000 — or: npm run build && npm run lint
cd storefront && npm run dev          # :3001
cd reseller && npm run dev            # :3002 (see reseller/AGENTS.md)

# E2E (ADR-023) — golden paths, Chromium only. Boots its own throwaway
# backend+DB, never touches the local dev DB, needs no external secret
# (supplier + CHIP are zero-network fakes under APP_ENV=e2e). Also runs in CI.
cd e2e && npm test
```

**Local symptom that looks like a code bug** (queued job never runs, "no such
table", a script's env var ignored by `serve`, a CI-only corrupted value from
captured artisan output): check `backend/AGENTS.md` → "Local dev gotchas"
first.
