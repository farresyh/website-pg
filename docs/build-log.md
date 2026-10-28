# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-15** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-14 (moved in eight passes, the last on 2026-10-16) to keep
> this file quick to read at session start.

---

## 2026-10-15 — Release #441 live; item 97 verification run failed on `partial_revokes`; fixed-name follow-up

**Release #441 (`staging`→`main`, merged by the founder) verified live:**
- `main` = `bd80f27` on the box.
- Horizon is running; `supervisor-backups` and `supervisor-price-sync` are on `redis-long`.
- `fail_stuck_backup_runs` ran, and #26–#30 are now `failed`.
- `app:restore-backup` exists, the schedule is 02:07, and `/api/health` returns 200.
- The latest Price Sync run succeeded with `fx_rates_used` 0.000228. There were 31 `currency_rate` cache reads in the last 30 minutes, against 1,521 per run before.

**Grant + verification run.**
- The founder ran the 2026-10-14 wildcard grant. `SHOW GRANTS` showed it.
- A manual run (#45, `triggered_by` "Claude (item 97 verify)") still failed: `1044 Access denied … 'defaultdb_restore_test_1792001735'`. Its failure alert went to the admins.
- Cause: the cluster has `@@global.partial_revokes = 1`, while local Docker and CI have `0`. With it on, `_`/`%` in a database GRANT are literal, so the grant matched nothing.
- The 2026-10-14 stress test missed this because the MySQL test ran under different server settings from prod.

**Follow-up (branch `fix/restore-test-fixed-db-name`).**
- The restore test always uses `<db>_restore_test`, dropping any leftover from a killed run first.
- `BackupRestoreMysqlTest` now runs with `partial_revokes = ON` and an exact-name grant. Red first: it reproduced the prod `1044`. A new case covers a leftover half-restored database.
- ADR-039 and ADR-117 2026-10-15 addenda (the replacement SQL revokes the useless literal grant).

**Left to do:** release, the founder's replacement grant, then "Backup Now" and a check for `restore_test_passed = 1`.

**Later 2026-10-15.**
- PR #442 is merged to `staging`, with CI green (the `backend-concurrency` job now runs under `partial_revokes = ON`).
- The founder ran the ADR-117 2026-10-15 SQL. Verified read-only: `pekangame_app` holds the exact-name grant on `defaultdb_restore_test`, and the wildcard grant is gone.
- The founder chose to release #442 together with the next backlog items, so release PR #443 was closed unmerged.
- Until that release, each nightly run still fails with `1044`, because the live code uses a timestamped name. Each run alerts the admins, and archives still upload.

## 2026-10-15 — Item 98: scheduler liveness (ADR-122), built on `feature/scheduler-liveness`

**Why.** Nothing noticed if scheduled work stopped. Prod runs `schedule:run` from one `/etc/crontab` line whose log is overwritten every minute. Five silent ways for scheduled work to stop: cron stops, `schedule:run` crashes at boot, a `withoutOverlapping` lock left by a killed process (1440-minute default, so a whole day), a task throwing every run, and seven `Schedule::call(fn () => Artisan::call(...))` tasks that swallowed a `FAILURE` exit code. Grilled in three rounds, then stress-tested against Laravel v13.21.1's scheduler source.

**What shipped.**
- `App\Console\TaskSchedule` is now the one schedule definition. `routes/console.php` calls it, and so does the liveness check: an HTTP request never loads `routes/console.php`, so its `Schedule` is empty. The tiers (`CRITICAL` / `ROUTINE`) live in the same class.
- The seven closures are now `Schedule::command`, and every task uses `withoutOverlapping(30)`. `horizon:snapshot` got a name.
- `scheduled_task_heartbeats` holds one row per task: `last_ok_at`, runtime, last failure and error. It is written by `RecordScheduledTaskHeartbeat` on `ScheduledTaskFinished` / `ScheduledTaskFailed`.
- `SchedulerLiveness` judges each task from its own cron expression and timezone:
  - Grace is 30 minutes, or 60 for a daily task.
  - A sub-daily task may miss one due run; a daily task may not.
  - A task with no row is seeded on first sight.
  - Rows for removed tasks are ignored.
- `/api/health` gained `checks.scheduler`: 503 when a critical task is stale. The response shows no task names. The admin Dashboard System Health panel gained a "Scheduled tasks" row listing stale tasks with their last OK time and last error.

**Tests.**
- `TaskScheduleTest` (invariant: unique name, one tier, 30-minute lock; the real scheduler registers the same set).
- `RecordScheduledTaskHeartbeatTest` covers: OK, a non-zero exit, an overlap skip not counted as OK, a failure keeping the last OK, and a write failure never escaping.
- `SchedulerLivenessTest` covers: seeding, sub-daily vs daily, the task's own timezone, the creation baseline, a removed task, critical vs routine.
- Health and Dashboard cases.
- Fast suite 2808/2808.
- One real local `schedule:run` at :05 wrote `horizon-snapshot` OK (0.3s).
- Admin `tsc`/lint/build are clean. Browser check in light and dark with a staged stale row: the chip and list render, and the error text measures 5.68:1 in dark.

**Gotchas hit (both from the stress test, both would have hidden real failures).**
- Laravel fires `ScheduledTaskFinished` for a task skipped by its overlap lock, with a `null` exit code. `null != 0` is false, so a naive listener records success for exactly the stuck-lock case. The listener checks `skippedBecauseOverlapping` and `exitCode === 0`.
- `ScheduledTaskFailed` is dispatched inside `ScheduleRunCommand`'s `catch`, so a listener throw would abort every other task due that minute. The listener catches and logs.
- The app timezone is `UTC` (hardcoded in `config/app.php`; prod's `APP_TIMEZONE` env is unused), so daily tasks run at 00:00 UTC (08:00 MYT). The liveness check uses the same default.

**Found on the way, not fixed (new §16 items).**
- **99:** Digiflazz `[83]` pricelist rate limit, 8 times since 2026-10-11, although the old box is gone.
- **100:** the admin's `dark:text-error-400` (145 uses) points to an undefined token, so dark-mode error text falls back to `error-600` at 1.83:1.

**Not live.** It ships in the next release bundle with #442.

## 2026-10-15 — Item 99: Digiflazz price-list rate limit was self-inflicted; own 30s timeout, no retry

**Found.** From production `supplier_request_logs`, 2026-10-11 to 2026-10-15, read-only:
- Digiflazz `/v1/price-list` takes 4.7s median, 8.5s p90.
- 42 of 249 calls hit the shared 10s timeout. Each was retried at once (a read timeout is a `ConnectionException`, so ADR-014 retries it), and 7 retries got `rc 83`, because Digiflazz had counted the timed-out request.
- Gamevion's price-list never timed out.

**Shipped (ADR-069 2026-10-15 addendum, branch `fix/digiflazz-price-list-timeout`).**
- `DigiflazzAdapter::listProducts()` uses `DIGIFLAZZ_PRICE_LIST_TIMEOUT_SECONDS` (default 30) and no retry.
- Transactions and `checkBalance` keep 10s plus the retry.
- `client()` gained `timeoutSeconds`/`retry` parameters. The provider passes the new config.

**Tests.** Red first:
- The price-list call gets 30s while `cek-saldo` gets 10s.
- A price-list connection failure is sent once.
- `checkBalance` still retries (4 sends).
- Fast suite 2811/2811.

No live call: a price-list from a local machine shares the production account's rate limit.

**Not live.** It ships with the next release bundle. Afterwards, watch the Digiflazz `listProducts` `exception` count and `rc 83` in `laravel.log`.

## 2026-10-15 — Item 100: admin status colours had no tokens; aliased to the ADR-104 inks, plus a CI guard

**Found.** In the item 98 browser check, dark-mode error text measured 1.83:1.
- `globals.css` defines `error/success/warning` 50, 500 and 600 only, so the ~190 `dark:text-*-400` classes produced no rule: Tailwind v4 silently drops a utility whose token is missing. Dark mode kept the light `-600`.
- A scan of every colour class found 22 more, in light mode, naming shades that don't exist: `text-error-700/800`, `text-success-700`, `text-warning-700`, `border-warning-300`. Those rendered in the inherited colour.

**Shipped (branch `fix/admin-status-color-tokens`).**
- `-400` aliases to the ADR-104 `*-ink` tokens, in `@theme static` next to the chart aliases (`.dark` sits on `<html>`, so each alias follows its ink's dark value).
- The 22 light classes now use existing shades.
- A dead `hover:text-dark-900` is removed.
- `color-tokens.test.ts` is the guard: red without the `globals.css` change (it listed the three `-400` tokens), green with it. The first version also flagged `primary-*`/`surface-*`; those come from `tailwindcss-primeui`, so the test reads that plugin's `theme/colors.css` instead of hardcoding exceptions.

**Verified.**
- Admin `npm test` 9/9, `tsc`, eslint and build are clean.
- Local browser with `getComputedStyle`:
  - Dark resolves error #f59c93, success #7fd6a0, warning #f0bd6a.
  - Light is unchanged (the 600s).
  - Dashboard "(12 failed)" and the Balance page's failed line measure 7.86:1 dark (was 1.83) and 8.96:1 light.

**Seen, not fixed (asked the founder).** Two headings with no colour class, the Dashboard `h1` and the login `h1`, show near-black on the dark canvas, because `body` sets `bg-canvas` but no text colour.

**Not live.** It ships with the next release bundle.

## 2026-10-15 — Item 100 follow-up: `body` gets `text-ink` (founder-approved)

**Why.** Text with no colour class inherited the browser's black, because `body` set `bg-canvas` but no text colour. On the dark canvas that was 1.28:1 (the Dashboard `h1`) and 1.20:1 (Recharts axis ticks on Reports).

**Shipped (branch `fix/admin-body-text-ink`).** `body` now applies `text-ink`, which flips with `.dark`.

**Verified with a before/after sweep.** Every static admin page plus `/login` (41 × light and dark = 82 views) was swept for text under 3:1 against its first opaque background.
- 158 items before, 140 after.
- Fixed: the Dashboard `h1`, the Reports axis ticks, and a Reports `-30` label.
- New: none. The one apparent "new" row was the Monthly Summary's own timestamp changing between runs.
- In light mode, unstyled text moves from #000 to ink #1b1a17.

**Seen, not fixed (pre-existing, outside this change).**
- Light-mode `gray-400` (#a8a192) secondary text and info icons measure 2.3–2.6:1 (around 70 instances, e.g. package unit suffixes, "i" tooltips).
- Dark-mode "Delete" buttons in `error-500` (#b42318) measure 2.5:1 (23 instances).

## 2026-10-15 — Release #449 live and verified; the new scheduler monitor caught a real stuck lock on day one

**Release #449 (`staging`→`main`, merged by the founder; items 97–100) verified on the box.**
- `current` = `30687ac`. `create_scheduled_task_heartbeats_table` ran.
- `/api/health` returns `{"database":true,"queue":true,"horizon":true,"scheduler":true}`.

**Item 97 closed.** Claude triggered "Backup Now" (`php artisan app:run-backup --triggered-by="Claude (release #449 verify)"`). Run #47: `success`, `restore_test_passed = 1`, 3.19 MB, 72s (under the 300s timeout). This is the first restore-verified backup since #25 (2026-09-24).

**Item 98: first real catch.** Within minutes, `wallet-topup-reconciliation` had no heartbeat while the other 15-minute tasks did. Read-only trace:
- At 06:44 UTC, unattended-upgrade installed `libsystemd` (among others), which restarted Redis (Redis uptime started 06:44:54). `laravel.log` shows `Predis ConnectionException`s at 06:45:03–06.
- The wallet task, starting at 06:45, took its `withoutOverlapping` lock but could not release it. The lock was the old code's 1440-minute one, with about 21h left, so the task would have been skipped silently every 15 minutes until about 06:45 the next day. No reconcile process was running (`ps`). No wallet top-up was pending, so there was no customer impact.
- With ADR-122 live, health would have gone 503 at about 10:15 UTC.
- **Remediation (prod write, run by the founder after Claude gave the exact command):** `Cache::forget('framework/schedule-5a0dc6393f179e512444bcf24d7381da6e3bd869')`.
  - Before: lock held, ttl 77,085s, heartbeat empty.
  - After: lock gone. The 09:30 run recorded `last_ok_at 09:30:06` (0.98s), and health stayed 200.
- New locks are 30 minutes (ADR-122), so this class now clears itself.

**Item 99:** too early to judge (the 09:00 price-list took 7.0s and succeeded). Re-check the `exception` count after a day.

## 2026-10-15 — Item 101: admin low-contrast leftovers fixed with design-system tokens; Dashboard redesign brief agreed

**Why.** The item-100 sweep left two groups under 3:1: light `text-gray-400` secondary text (2.3–2.6:1) and dark-mode `text-error-500` Delete links (2.5:1). The founder pointed out that the "PekanGame Admin" artifact already defines the right colours. So this is a token swap, not a mockup wait (ADR-104 2026-10-15 addendum).

**Shipped (branch `fix/admin-low-contrast-leftovers`).**
- New token `--color-ink-subtle`, using the artifact's values: light #716b62, dark #9a948a.
- All 135 base `text-gray-400` classes (66 files) now use `text-ink-subtle`.
  - Where a `dark:text-gray-500/600` partner sat next to it, that partner was dropped so the token's own dark value applies.
  - Two non-adjacent stale partners (InfoTooltip, TierCard) were removed by hand after the browser check caught the tooltip "i" at 3.96:1.
- The Assistant "Thinking…" bubble sits on `gray-800`, so it uses `ink-muted` instead.
- 5 base `text-error-500` classes now use `text-danger-ink`, which flips in dark. Two `dark:text-{error,success}-500` classes are now `-400` (same dark bug as item 100).
- `color-tokens.test.ts` now fails on a base `text-gray-400` / `text-error-500`. It was proven red by restoring two old files.

**Verified.**
- Admin `npm test` 10/10, `tsc`, eslint and build are clean.
- Local built admin + Playwright, 10 pages × light/dark, `getComputedStyle` on every changed element:
  - Minimum 4.8:1 light, 5.45:1 dark.
  - SEO FAQ Delete: 2.5 → 7.86:1 dark.
  - Screenshots of Affiliates in both themes look right.

**Dashboard check (read-only prod).** The founder suspected the KPI and Hourly data was broken.
- `DashboardService` outputs match raw `orders` rows exactly: summary 0 today; hourly 3 at 23:00 on 10-05 and 1 at 18:00 on 10-10; funnel 1/1/1; top game PUBG 1 order.
- Nothing is broken. The last order was 2026-10-10 10:00 UTC; payments have been off since 10-07.
- The redesign brief is recorded as ADR-104 2026-10-15 addendum and PRD §16 item 102.

**Not live.** It ships with the next release bundle.

## 2026-10-15 — Admin dead-code sweep (PR-0 of the Dashboard redesign, item 102)

**Why.** The founder asked for an inventory of unused TailAdmin-era leftovers before the Dashboard grill, so they could decide what to delete. This PR removes only things with zero references.

**Removed.**
- `zod` from `admin/package.json`. It had 0 imports in `admin/src`. It stays in the lockfile as `eslint-plugin-react-hooks`'s own dependency.
- The 5 create-next-app SVGs in `admin/public/` (`file`, `globe`, `next`, `vercel`, `window`), with 0 references.
- Unused `globals.css` tokens, all with 0 class uses and 0 `var()` uses:
  - `--color-brand-25`, `--color-blue-light-50/500`, `--color-gray-25`, `--color-gray-dark`
  - `--text-theme-xl`
  - `--breakpoint-xsm` and `--breakpoint-3xl`. This revises ADR-104 decision 2's "structural tokens untouched" for these two; `2xsm` is still used once and stays.

**Kept on purpose.** These TailAdmin-era classes are still live on the screens that haven't been reskinned yet: `gray-*` (~3,000 uses), `text-theme-*` (~1,000), `brand-*` (216), `rounded-2xl` (138), `bg-white/[0.03]` (144), `shadow-theme` (35), and the `menu-item` utilities in `PanelSidebar`. They go as each screen is reskinned, not by deletion. Dashboard-only components and endpoints are deleted in the Dashboard build PR itself.

**Verified.** Admin `npm test` 10/10, `tsc`, eslint and build are clean.

## 2026-10-15 — Dashboard redesign, backend PR (item 102)

**Why.** This is the backend half of the redesigned Dashboard (ADR-104 2026-10-15 grill-outcome addendum). The founder approved the mockup in the design-system artifact first: one Sales trend card, not a sparkline per KPI.

**Shipped (branch `feature/dashboard-backend`, test-first).**
- **`PeriodComparison::previousRange`, which Reports uses too.** A range still running is compared up to the same elapsed time: today to 10:30 vs yesterday to 10:30, and likewise 7/30 days and month to date. `previous_range` gains `until` (`"10:30"`, else null), and `to` names the clipped day. Reports' Compare figures for ranges ending today change accordingly.
- **`ReportService::busyHours()` on `/reports/busy-hours`.** Paid orders per KL hour of `paid_at`, 24 rows, zero-filled. It uses the same population as Sales (`scopedOrders`) and a new `hourBucketExpr` (sqlite + MySQL).
- **`ReportService::highlights()` on `/reports/highlights`.**
  - `best_day`, with `is_all_time_record`. It is null for a one-day range, and ties go to the earlier day.
  - `top_reseller`, from `resellerBreakdown()`.
  - `biggest_order`, using net sales like Sales.
  - Each fact is null without data.
- **`attention` inside `/dashboard/health`**, so the status strip and the health panel come from the same poll and the same numbers. Items are `{key, level, count, subject}`, plus `needs_action_count` (red and amber items only) and `last_paid_at`. Choices found while building:
  - `stuck_orders` in health already includes NeedsReview, so the attention `stuck_orders` item counts only stale processing/pending orders. `needs_review` is its own item, and no order is counted twice.
  - A null OpenWA session only means no event in the last hour, so it is never flagged. Only an explicit non-connected status is.
  - "Withdrawals to process" counts Pending and Approved: an approved one still waits for the admin's transfer.
  - "Payments off" is neutral, and only when at least one method exists and none is active.
  - "Backup failed" means the latest finished run failed.
- The old `/dashboard/summary`, `/funnel`, `/top-games` and `/hourly-activity` stay until the frontend PR replaces their caller.

**Verified.**
- Full backend suite: 2,835 passed.
- The 71 Report/Dashboard tests also pass on real MySQL, which exercises `HOUR(CONVERT_TZ())` and the aliased `ORDER BY`.

**Not live.** It ships with the frontend PR in the next release.

## 2026-10-15 — Dashboard redesign, frontend PR (item 102)

**Why.** This is the frontend half of the Dashboard redesign. It follows the mockup the founder approved in the design-system artifact (ADR-104 2026-10-15 addenda).

**Shipped (branch `feature/dashboard-frontend`).**
- **`/admin` rebuilt as a daily briefing.**
  - The header subtitle is the summary sentence. It is built from data in `lib/dashboard-briefing.ts` and tested.
  - The period switch offers Today, 7 days, 30 days and Custom, and always opens on Today.
  - The status strip and its System details panel are both read from `/dashboard/health`, polled every 60s.
  - KPIs (Sales, Owner profit, Orders, Margin) show compare labels like "vs 14 Oct, to 8:48 PM".
  - Cards: Sales trend (`TrendChart`), Channels, Top games (with change), Busy hours (peak and quietest 3-hour windows) and Highlights.
  - Every business figure comes from `/reports/*`.
- **Shared with Reports.**
  - A **Today** preset in `date-range.ts`, which Reports and Orders also get.
  - `previousLabelOf` shows the backend's `until` ("…, to 10:30 AM").
  - `formatShortRange` prints one date for a one-day range.
- **Reviews** opens on `?status=pending`, the Dashboard's "reviews pending" link.
- **Backend.** `/reports/top-games?compare=` (`ReportService::gameComparison`, tested).
- **Removed.**
  - The old `/dashboard/summary|funnel|top-games|hourly-activity` endpoints, service methods and tests.
  - `DashboardKpiCard`, `StatCard`, `ComparisonBadge`, `ConversionFunnelChart` and `HourlyActivityChart`.
  - `lib/dashboard.ts`'s dead types and fetchers.

**Verified.**
- Admin: `tsc`, eslint and build are clean, and `npm test` passes 14/14.
- Backend: the full suite passes 2,811 (down from 2,835 because of the removed old-endpoint tests).
- In a live browser (built admin + local API, light, dark and 390px):
  - Today empty: the sentence names the last order, empty cards explain themselves, and Highlights is hidden.
  - A Custom range with data matches the mockup's layout.
  - Every text element is ≥ 4.5:1, with no horizontal scroll.

**Not live.** It ships with the next `staging`→`main` release.

## 2026-10-15 — Docs sync for the session (items 100–103)

Swept the docs this session touched for claims it made untrue (docs only).
- **PRD §14:** "No code is waiting on `staging`" was untrue. It now names #450–#455 (item 101, the dead-code sweep, the Dashboard redesign) and the Reports Compare change they carry.
- **PRD §15:** the Reports row records the same-time Compare, the Today preset and the three new `/reports/*` endpoints as on `staging`.
- **PRD §16:**
  - Item 100 closed (released and verified in #449).
  - Items 101 and 102 moved to a new "On `staging`, waiting for release" group.
  - Item 103 moved to "Buildable now" as the next session's first item.
- **ADRs:**
  - ADR-045's Standing records that ADR-104's 2026-10-15 addenda replace DASH-1, 3, 4 and 5. DASH-2 health stays.
  - ADR-045 gets a 2026-10-15 addendum saying the same.
  - ADR-104's banner and index row name the revision.

## 2026-10-15 — Customer Analytics reads that scale (item 103)

**Why.** Audit of item 103: every Customer Analytics request loaded every
customer (and, for names, every order of them, any payment status) into
PHP; the list was unpaginated and rendered in full; the detail page loaded
all of a customer's orders. Synthetic benchmark on local MySQL 8.4 at 10k
orders / ~2.7k customers: 0.4–0.5 s and 46 MB per call, against 63–130 ms
for the Reports aggregates. Also found: two copies of net sales (SQL list,
PHP detail), a segment that changed with the Affiliate/Reseller filter, and
`MAX(wallet_reseller_id)` labelling `faris9745@` (4 wallet + 3 Affiliate
orders, prod) "Reseller" for all 7.

**What.** Grilled in four rounds, stress-tested, ADR-049/050 2026-10-15
addendum. Backend PR: one per-customer SQL aggregate for stats, list,
export and detail stats; segment as a SQL `CASE` with cut-offs bound from
PHP (same SQL on sqlite and MySQL; Carbon 3's fractional `diffInDays()`
makes `< now − 60 days` exact); segment, repeat rate and Source from every
paid order whatever the filters; paged/sorted/searched list; export via
`cursor()`; paged detail order history; `CustomerAnalyticsFilterRequest`
(bad value = 422); Source `Reseller: A + Retail`; `from`/`to` dates, and
`ReportService::dateRangeForYearMonth()` removed (last caller);
`MalaysiaTime::dateSql()` now holds the day-bucket SQL Reports had.
Profit Analysis keeps the per-order `Order` methods (the only copy of the
ADR-094/105/111 cost rules), read in chunks. Frontend PR: paginator, sort,
debounced email search, Reports date presets, Source tag, KL dates, and a
filter change aborts the request it replaces.

**Verified.** Parity test pins the old implementation's figures for a fixed
data set (every segment boundary, a wallet refund, a mixed email, excluded
orders). Old vs new on the whole local dev DB (183 orders, 40 customers):
0 differences in list, range list, stats, row order and every detail page.
Fast suite 2828/2828; the 51 Customer Analytics tests also on MySQL; new
`tests/Concurrency/CustomerAnalyticsMysqlTest` proves letter-case variants
are one customer on MySQL. Benchmark at 10k orders, old → new: stats
409 → 137 ms, customers 502 → 124 ms, month filter 455–480 → 84–89 ms,
46 MB → 0.1 MB, list JSON 620 KB → 8 KB. Browser (local, light/dark/phone):
40 customers over 2 pages, one request per typed search, stat cards not
refetched on search, no page-level horizontal scroll, no API errors.

**Gotchas.**
- `addcslashes()` escapes with a backslash, not the `!` the `LIKE … ESCAPE '!'`
  expects; `strtr()` does it. sqlite has no default LIKE escape, hence `!`.
- The first local MySQL run errored all 112 tests from the first one on:
  `llm_report_*` views left in docker `kerox` by an earlier run (backend/AGENTS.md
  gotcha 8). Dropping them fixed it; the new `DatabaseMigrations` test was
  checked afterwards and leaves no view behind.
- Admin lint rejects `setState` inside an effect: loading is derived from
  which request an answer belongs to, and page reset happens in handlers.
- Left as is, with a `ponytail:` note: Profit Analysis costs one query per
  realised order, from `Order::cashCompensationSen()` (shared with
  OrderController and Accounting). The "All time" list is 124–137 ms at 10k,
  just over the 100 ms aim, from two scans per call (count + page, totals +
  top spender) and `netSalesSql()`'s correlated subquery.

## 2026-10-15 — Docs sync for the item 103 session (docs only)

- PRD §16 gained item 104 (reseller wallet top-up KPIs, group D with item 28;
  not part of ADR-115, which forecasts supplier drain, #457) and item 105
  (Profit Analysis one query per order via `Order::cashCompensationSen()`,
  Pulse trigger, #460).
- PRD §14: the staging list now runs #450–#460 and names item 103's API change.
- ADR-088 decision 4 ("`dateRangeForYearMonth()` is kept for Customer
  Analytics") retired by ADR-049's 2026-10-15 addendum: dated addendum added,
  index date updated.

## 2026-10-15 — Release #462 (`staging`→`main`): items 101–103, verified

Founder merged #462 (`9b5ff74`, #450–#461). CI deploy green; the prod box's
`current` is release 80314022 at `9b5ff74`; `/api/health` 200.

Checks (read-only tinker over SSH, and the founder's logged-in Chrome):
- Item 103: 3 customers; list total 22070 sen equals Reports' `total_sales`,
  4 + 21 + 7 = 32 orders equals Reports; `faris9745@` shows
  `Reseller: FixFast + Retail`; search `FA`, the October filter and the
  `faris9745@` detail page (7 orders, Top Sources FixFast and Ohahastore) work.
  The admin page renders it, names from paid orders included.
- Item 102: Last 30 days on the Dashboard (RM210.10, 27 orders) equals
  `/api/reports/summary` for 16 Sept–15 Oct (21010 sen, 27). Dark and narrow
  (606 px, Chrome's minimum) render with no horizontal scroll.
- Item 101: `getComputedStyle` contrast on prod: `text-ink-subtle` 4.80:1
  light / 5.45:1 dark, `text-ink-muted` 7.86 / 8.82, `text-danger-ink`
  5.70 / 8.42.
- The Dashboard's "7 failed jobs" are old rows, not this release: reseller-bot
  OpenWA 401s on 2026-09-25 (box migration) and backup timeouts 26–30 Sept
  (fixed by item 97). Clearing them is a prod write, left to the founder.

Items 101, 102 and 103 closed (PRD §16).

## 2026-10-16 — Items 98 and 99 closed after a day of prod data (docs only)

Read-only checks over SSH (tinker, `laravel.log`, `/api/health`) at
05:05 UTC, about 20 hours after #449 went live (release 80273185,
2026-10-15 09:08 UTC).

- **Item 98 (ADR-122).** `scheduled_task_heartbeats` holds 19 rows, one per
  task in `TaskSchedule` (11 `CRITICAL`, 8 `ROUTINE`). Every row has a
  `last_ok_at` on 2026-10-16 and `last_failed_at` null. The daily tasks ran at
  00:00, then `membership-renewal-reminders` at 02:00, `database-backup` at
  02:07 (its `BackupRun` is `success`) and `database-backup-cleanup` at 03:00.
  `/api/health` returns 200 with `checks.scheduler: true`. Longest runtime
  12.29 s (`database-backup-cleanup`), so the 60-minute daily grace has a lot
  of room; revisit it after a week of runtimes, as ADR-122 says.
- **Item 99 (ADR-069 2026-10-15 addendum).** Digiflazz `supplier_request_logs`,
  before vs after the deploy: `listProducts` 212 success / 42 exception (max
  10,008 ms, the old 10 s timeout) from 2026-10-11, against 40 success / 0
  exception (max 19,530 ms) since. That 19.5 s call would have timed out and
  been retried under the old setting. `laravel.log` has 7 rc 83 lines, the last
  at 2026-10-15 03:00:23, before the deploy. `PriceSyncRun`: 48 a day, every
  one `success`.

Seen during the check, not part of either item:
- Gamevion's `listProducts` timed out twice (2026-10-16 02:00 and 02:30 UTC,
  `cURL error 28` at 10 s, 0 bytes received). The next run succeeded. Gamevion
  still uses the shared 10 s timeout. Its `PriceSyncRun`s were still recorded
  `success`, because one supplier failing doesn't fail the run, so the failure
  shows only in `laravel.log`. Logged as PRD §16 item 106 (outage clusters, not
  slowness: p99 5.9 s over 1,252 calls).
- A Redis connection blip on 2026-10-15 06:45 (about 30 Predis errors over
  5 s, on the release before #449). It recovered by itself, and nothing
  followed it.

Items 98 and 99 closed (PRD §16).

## 2026-10-16 — MLBB player validation: RedxGame → MooGold (ADR-005 addendum)

**Found (read-only, prod).** `player_validations` showed the last
AcidGameShop result on 2026-10-09; from 2026-10-10 only MooGold answered.
Fallback warnings in `laravel.log`: AcidGameShop 502 then, since its site
rebuild, `response missing nickname on a 200` (the endpoint now serves the
SPA's HTML); Nexone's `/idchecker` 403. MooGold, re-run once on prod with an
ID AcidGameShop had mapped to `MY`, returned `valid` with no country. So every
MLBB check ended `region_unknown`, which blocks checkout. No customer hit:
payments have been off since 2026-10-07.

AcidGameShop's new "Check Region ML" page calls an obfuscated API behind a
token step; left alone on purpose (ADR-005 addendum). RedxGame's checker
(`redxgame.com/tools`) is plain: `POST /tools/check` with its session cookie
and CSRF token, answering nickname plus an English country name. Founder's
real accounts (MY, ID, RU, BR, TR, KR) all mapped correctly, from local and
from the prod box.

**Built.**
- `RedxGameValidator`: cached session (60 min), one renewal on a rejected
  cached session, `status:false` thrown as unavailable so MooGold decides
  (founder's call), 5 s timeout.
- `CountryCodeDecoder::fromCountryName()` via ICU over the timezone DB's
  current codes, alias `Turkey` → `TR`, unknown → null + a log line.
- Removed `AcidGameShopValidator`, `NexoneValidator`, their config and tests,
  and `fromFlagEmoji()`. Comments and test labels naming them updated.

**Verified.** Fast suite 2834/2834. The real chain from local (tinker, the
`mlbb` registry binding): six real IDs → `redxgame` with MY, ID, RU, BR, TR,
KR in 1.5–2.2 s; a made-up ID → `moogold` invalid in 3.96 s. The HTTP
endpoint wasn't exercised locally (the dev DB has no validator profile or
mappings); `PlayerValidationController` is unchanged and its tests pass.
After release: run "Test this validator" on `/middleware/validators` in prod.

**Gotchas.**
- ICU names retired codes with today's names (`BU` "Myanmar (Burma)", `DD`
  Germany, `SU` Russia, `YU` Serbia), so walking AA..ZZ maps Myanmar to `BU`.
  The timezone database lists only current codes (247).
- Prod ICU is 74.2, local 77.1; names can differ between ICU versions, so the
  tests assert only names Redx was seen returning.
- The auto-mode classifier blocked sending a prod customer's player ID to
  Redx; the founder's own test accounts were used instead.

## 2026-10-16 — Free Fire player validation via RedxGame (ADR-005 second addendum)

Surveyed Redx's other checkers against our catalogue (read-only, test IDs):
Free Fire answers like MLBB (country name); Genshin and HSR answered a
server ("China" for UID 123456789); Valorant answered Redx's own store verdict
("registered in another region, so it cannot be topped up here"); ZZZ
answered `status:false` for the test UID. Only Free Fire is built.

Founder's real Free Fire accounts: Indonesia → "Indonesia", Thailand →
"Thailand", Singapore → "Singapore", Malaysia → "Singapore" (the Garena
server MY and SG share). The founder will deactivate Free Fire Singapore and
map `SG` → Free Fire Malaysia themselves, so no mapping-model change.

**Built.** `freefire` key in `PlayerValidatorRegistry::AVAILABLE_KEYS` and
its binding; `RedxGameValidator` takes the Redx game slug, sends `server_id`
only when present, and for Free Fire treats `status:false` as invalid (no
second provider; founder's call). New invariant test: every selectable key
resolves to a bound validator.

**Verified.** Fast suite 2839/2839. Real calls through the `freefire`
binding from local: ID, TH, SG, SG (the Malaysian account) and a made-up ID
→ invalid, 2.2–2.7 s; `mlbb` still → MY.

**After release (founder, admin):** create a "Free Fire" profile with key
`freefire`, map the countries (`SG` → Free Fire Malaysia, `ID`, `TH`, `PH`,
the rest → Free Fire Global), deactivate Free Fire Singapore, then turn on
"Requires Player Validation" per Free Fire game and run "Test this validator".

## 2026-10-16 — Genshin / HSR / ZZZ zone validation via RedxGame (ADR-005 third addendum)

**Found.** Redx answers these three with the UID's server: "Asia" for the
founder's six UIDs; one public UID per range gave "Americas" and "Europe"
(Genshin, ZZZ) and "China" (Genshin 1xxxxxxxx). Our zone value is "America",
the supplier's format, so the label is translated, never compared raw.
Valorant: four MY Riot IDs → "Malaysia", one (`nothingtosee#007`)
"Couldn't verify" twice; not built.

**Built.**
- `genshin`/`hsr`/`zzz` keys; `RedxGameValidator(regionIsServer)` fills
  `PlayerValidationResult::$server`/`$zone`.
- `ZoneCheck`, applied by `PlayerValidationController` and both bot paths:
  new `wrong_zone` state.
- Storefront: the `wrong_zone` message with a "Switch to X" button.
- Admin test panel shows the server.
- Redx-only games (Free Fire too) now time out at 10 s, not 5.

**Verified.** Fast suite green, plus storefront `tsc`, lint and build.
Real calls: Asia UID + Asia → valid, + America → `wrong_zone` (zone Asia);
Americas UID + America → valid; China UID → `wrong_zone`, no zone; Europe ZZZ
UID + Asia → `wrong_zone` (zone Europe); an unknown UID → invalid in 6.9 s
(it had timed out at 5 s). Browser (local, 420 px, a temporary local game
removed afterwards): wrong zone → "Switch to Asia" → zone select set to Asia,
"Valid account found"; China UID → "doesn't sell"; warning text
rgb(25,25,47) on rgb(255,215,0).

**Gotcha.** `vendor/bin/pint app` reformats about 30 unrelated files; run
Pint on the changed files only.

## 2026-10-16 — Release #469 (`staging`→`main`): RedxGame player validation, verified; docs sync

Founder merged #469 (`12643a7`, #463–#468). CI deploy green; the prod box's
`current` is `12643a7`; `/api/health` 200 with `scheduler: true`.

Checks (read-only tinker over SSH):
- `PlayerValidatorRegistry::AVAILABLE_KEYS` on prod: `mlbb`, `freefire`,
  `genshin`, `hsr`, `zzz`.
- Profiles the founder created: Free Fire (7 mappings: SG → Free Fire
  Malaysia, TH, PH, ID to their games, BR, IN, VN → Free Fire Global),
  Genshin, HSR, ZZZ (no mappings, as designed). Genshin's "Test this
  validator" ran: `valid … server: Asia, via redxgame`.
- Validator toggled on for all six Free Fire games, Genshin MY, HSR MY, ZZZ
  and nine MLBB games.
- `player_validations` since the deploy: all `provider = redxgame`; 2 valid,
  2 `wrong_region`, 2 `wrong_zone`. The founder tested the storefront and
  the bot (`.checkid` reports the other zone).
- Payment methods: still 0 active (founder's choice). Validation no longer
  blocks re-enabling them.

Settings flagged to the founder, not changed (prod data is theirs):
- Free Fire Singapore is still active, with its validator on. With `SG` →
  Free Fire Malaysia, every MY/SG account there gets `wrong_region` → "Go to
  Free Fire Malaysia", so it can't be bought validated.
- MLBB mapping `VN` was labelled "Venezuela" → Mobile legends Global. The
  system reads only the code, and `VN` is Vietnam (Venezuela is `VE`): a
  Venezuelan account got `region_unknown`, a Vietnamese one at Mobile
  Legends Vietnam got `wrong_region` → Global. **Fixed by the founder the
  same day:** `VE` → Mobile legends Global, `VN` → Mobile Legends Vietnam
  (verified). Cross-check of every mapping's code against its typed name:
  only cosmetic spellings left (`CO` "Columbia", `KR` "Korea"); Free Fire's
  seven all match.
- The MLBB profile's last panel test is still the 2026-10-09 AcidGameShop
  one (real checks since the deploy all ran through redxgame).

Seen, not changed: admin `ValidatePlayerForResendResult` (resend modal) lists
four statuses, not `wrong_zone`. Behaviour is right (anything but `valid`
shows "Not valid — cannot resend"); only the type is behind.

**Docs sync.**
- `build-log.md` entries 2026-10-13 → 10-14 moved verbatim to the archive
  ("Eighth archive move"); PRD §16 "Recently closed" items closed
  2026-10-08 → 10-12 moved verbatim ("PRD §16 closed items moved
  2026-10-16"). Pointers updated: the build-log and archive headers,
  root `AGENTS.md`, PRD §14 and §16.
- PRD §14: latest release #469, nothing on `staging`. §15: player
  validation live. §16: item 107's trigger met; re-check note made exact.

## 2026-10-16 — Public Game ID Checker (ADR-123, PRD §16 item 107)

**Design.** Grilled with the founder in seven rounds, then stress-tested
against code and prod (read-only) before the build. Prod scale: 13
`player_validations` rows ever, 18 validator-enabled stores over 5 families.
The stress test changed one decision: the founder's `source` column on
`player_validations` became a separate `public_player_checks` table, because
`player_validations.game_id` is NOT NULL (a family check with no store has
no game) and two readers already approve from it (checkout and
`OrderResendService`), so a forgotten filter would let a public check
approve a payment. Other findings fixed in the design: MLBB's public path
binds RedxGame alone (MooGold is our supplier); a 2-minute cooldown after a
provider failure; the brand-hidden-game filter existed twice and now has one
home (`AffiliateGame::hiddenGameIdsFor`); Turnstile fails closed.

**Built.**
- Backend: `POST /api/player-check/{slug}` (`throttle:player-check`
  5/min + 30/hour per IP, then Turnstile, input format, 10-minute Redis
  result cache, cooldown, 200/hour global budget with a once-a-Malaysia-day
  admin email through `BackupFailureAlerter`), `GET
  /api/catalog/player-check/families[/{slug}]` with `indexable` from
  `affiliates.is_primary`. `player-validator.public.{key}` for the five keys;
  the standalone RedxGame bindings now come from one factory.
  `public_player_checks` is pruned by `app:prune-player-validations`.
  Validator profiles gained `public_name` / `public_slug` / `public_image_url`.
- Admin: a "Game ID Checker" panel on each validator card (name, slug,
  gallery image).
- Storefront: `/check-id` and `/check-id/<slug>` (how-to-find-your-ID steps,
  FAQ with `FAQPage` JSON-LD, our own copy; redxgame.com/tools renders by
  JavaScript, so it couldn't serve as more than a topic list), sitemap and
  `llms.txt` on the primary brand only, `noindex` elsewhere. The order page
  prefills `?player_id=&server_id=` and doesn't validate on load. Mobile
  bottom bar: [Home, Membership] · centre **More** (native `<dialog>`
  drawer: Menu = Game ID Checker, Price List; Info = About Us, Terms,
  Privacy) · [Track Order, Support]. Header and footer link the checker.
  CSP allows `challenges.cloudflare.com` (script + frame). The ID-field
  helpers moved from `Step1AccountInfo` to `order/idFields.ts`.

**Verified.**
- Fast suite green (2,869 tests, 14 new in `PlayerCheckControllerTest`
  plus a prune test). Admin `tsc` + lint; storefront `tsc`, lint, build.
- Local real run (Cloudflare's always-pass test keys passed as process env,
  `.env` untouched; a temporary local MLBB profile and game removed
  afterwards). The founder's MLBB 51049607/2005 → `found`, "Prime.", store
  Mobile Legends, no provider or country in the response. Second check
  `cache_hit = true`; `player_validations` stayed at 0.
- Browser: the check shows the result card; "Top up" opened
  `/order/mobile-legends?player_id=51049607&server_id=2005` with both fields
  filled and no automatic verify. Playwright at 390 px: the drawer sits at
  the bottom with full width, Escape closes it, it opens again, a drawer
  link navigates and closes it, no horizontal scroll.

**Gotchas.**
- `php artisan serve` drops exported env vars (backend gotcha 3), so the
  local run used `php -S` from `public/` with `server.php`.
- Turnstile keeps the network busy, so Playwright's `networkidle` never
  settles on a checker page; wait for `load`.

**Founder setup before it works in prod.** Create a Turnstile widget in
Cloudflare for every storefront hostname (affiliate custom domains too). Set
`NEXT_PUBLIC_TURNSTILE_SITE_KEY` on the storefront Vercel project and
`TURNSTILE_SECRET_KEY` in the Forge `.env`. Fill name, slug and image on
each validator profile. Until then the pages exist, but no family is listed
(no slugs yet) and the endpoint answers 503.

**Same day, after the founder's review in their own Chrome (PR #471).**
- `/check-id` picks a family from large cards in the home page's
  `ProductCard` style (16:10 image, "Check ID" where the price sits),
  centred; the family page keeps the compact switcher. At 390 px the card
  footer drops "Free ID check" so "Check ID" stays on one line.
- Header nav: "All Products" had always been underlined in purple. The
  highlight now follows the page (`aria-current`): All Products on home and
  order pages, Membership and Game ID Checker on their own pages, none
  elsewhere. Checked with `getComputedStyle` on six pages (active
  `rgb(107, 56, 212)`, others transparent border).
- CI's first run failed one unrelated test,
  `CustomerAnalyticsListingTest::…tie_break`. Two orders built with
  `now()->subDays(1)` a moment apart landed in different seconds on a slow
  run, so the email tie-break never decided. Fixed with `freezeTime()`. It
  had passed five local reruns.
- Founder's Chrome run: an ID with a space was blocked in the form; wrong
  Server ID → "No account found"; 51049607/2005 → "Prime.", Top up →
  order page prefilled, not verified.

## 2026-10-16 — Release #472 (`staging`→`main`): Game ID Checker live, verified; docs sync

Founder merged #471 into `staging` (merge commit) and released #472
(`c48659d`). The prod box runs it; `migrate:status` shows both 2026-10-16
migrations ran.

**Founder's prod setup, same day.**
- Cloudflare Turnstile widget "PekanGame Game ID Checker" (Managed mode,
  pre-clearance off), created with the founder in their Chrome. Hostnames
  `pekangame.com`, `pekangame.space`, `fixfastapp.com` (the one active
  affiliate domain, read from `affiliate_domains`); 3 of the widget's 10.
  Site key `0x4AAAAAAFYZZNQMJTpU31qh` (public); the secret was copied by the
  founder only.
- `NEXT_PUBLIC_TURNSTILE_SITE_KEY` on the storefront Vercel project and
  `TURNSTILE_SECRET_KEY` in the Forge `.env`, set by the founder.
- Name, slug and image on all five validator profiles.

**Verified on prod.**
- Before the slugs, `/check-id` was 404 and the families list empty:
  by design (a family without a slug isn't listed). That looked like a bug
  to the founder, so the empty state is worth knowing.
- After: `GET /api/catalog/player-check/families` lists `free-fire`,
  `genshin-impact`, `honkai-star-rail`, `mobile-legends`,
  `zenless-zone-zero`, each with a gallery image. `/check-id` and
  `/check-id/mobile-legends` 200; `sitemap.xml` has the six checker URLs;
  no robots meta on the primary brand; `llms.txt` 200.
- In the founder's Chrome on `pekangame.com/check-id/mobile-legends`: the
  Turnstile token arrived ("Ready to check."), the founder's MLBB
  51049607/2005 → "Account found: Prime.", "tops up at Mobile Legends
  Malaysia". `public_player_checks`: row 1 the founder's own check
  (`cache_hit` false), row 2 this one (`cache_hit` true, within 10
  minutes); `player_validations` had no new row.

**Docs sync.** PRD §14 headline (latest release #472), §15 Game ID Checker
row live, §16 item 107 closed and item 108 added (Turnstile widget hostnames
follow affiliate domains, waiting on ~5 active affiliate domains), §13
glossary (Game ID Checker, Game family), `foundation-security.md` §4 (the
checker's anti-proxy protections).

**Local cleanup.** The temporary local MLBB profile, mapping and game
change were removed from the dev DB; the local servers were stopped.

## 2026-10-17 — Dependency advisories + storefront route skeletons (ADR-071 addendum, PR #474)

**Why.** A read-only security check of the 10 standard controls found
`composer audit` reporting 5 advisories on the live lock. The founder also
noticed that tapping the header logo from a game page froze on the old
page, then jumped to home with no skeleton.

**Dependencies.** Targeted `composer update` of `laravel/framework`
(13.21.1 → 13.35.0), `symfony/http-foundation` + `http-kernel` (8.1.1 →
8.1.9), `league/commonmark` (2.10.3), `league/flysystem` (+`-local`). The
one that mattered: `symfony/http-foundation` 8.1.1 had an unauthenticated
ReDoS through the `Cache-Control` request header (CVE-2026-108082).
`--with-dependencies` was rejected because it pulled two major bumps
(`brick/math` 0.18 → 1.0, `guzzlehttp/uri-template` 1 → 2) that no
advisory needed. `composer audit` is now clean; `php artisan test`
2869/2869 passed. npm: admin, storefront, reseller and e2e had 0
advisories. docs-site has one moderate (`postcss-selector-parser`, through
Starlight's expressive-code). It is build-time only, and npm's only offered
fix is a Starlight downgrade, so it was left alone.

**Skeletons, root cause.** ADR-071 left `/` and the legal pages without
`loading.tsx` because they were static/ISR. ADR-060 PR-3 (`51a0fa2c`) made
every SSR fetch read `headers()` for the per-Host brand, so `next build`
now lists every route as `ƒ` dynamic. A dynamic route with no loading
boundary keeps the old page until the new render ends. Only the three
order/track routes had one; the planned progress-bar catch-all was never
built. Fix: matched skeletons for `/`, `/price-list`, `/check-id`,
`/check-id/[slug]`, `/membership`, `/about-us`, `/terms` and `/privacy`.
`/membership`'s text "Loading…" Suspense fallback now uses the same form
skeleton. `storefront/scripts/check-loading-boundaries.mjs` runs in the
storefront CI job and fails a `page.tsx` without a `loading.tsx` (exempt:
`/store-unavailable`, which never suspends). Verified that the guard fails
when one is removed.

**Verified.** In the e2e harness (production `next build` + throwaway
backend), with RSC responses delayed 1.5s in Playwright: header logo
`/order/<slug>` → `/` showed the home skeleton after 15ms, and
`/membership` / `/track-order` after 8–9ms. Routes reached only through
off-screen footer links aren't prefetched, so their skeleton waits for the
server's first byte. The screenshot matched the home layout.

**Gotcha.** The Vercel preview (`*.vercel.app`) can't be used to check
storefront pages since ADR-060 PR-5: `proxy.ts` rewrites an unknown host to
`/store-unavailable` (503). Use the e2e harness, where `localhost` is a
primary host.

## 2026-10-17 — Weekly dependency audit (alert only)

**Why.** PR #474 found `symfony/http-foundation` 8.1.1 live on `main` with an
unauthenticated ReDoS advisory published a month earlier. Nothing scanned
lockfiles on a schedule, so it was found only by a manual check.

**What.** `.github/workflows/dependency-audit.yml`: every Monday 09:00 MYT
(plus manual `workflow_dispatch`, plus any PR touching a lockfile) runs
`composer audit --locked` and `npm audit --omit=dev` per app. It reads
lockfiles only, with no install, so it takes a few CI minutes a week. npm
thresholds: moderate for backend/admin/storefront/reseller; high for
docs-site, whose only moderate (Starlight's expressive-code chain) has no
fix short of a downgrade. Composer fails on any severity, because
CVE-2026-108082 arrived with no rating. It is a separate workflow from `CI`
and not in `deploy`'s `needs`, so a new advisory never blocks a release.
It never upgrades anything: the response is a targeted upgrade branch,
not `--with-dependencies` (#474 showed that pulls unrelated majors).

**Where alerts land.** GitHub emails a failed scheduled run to the account
that last changed the workflow's cron, i.e. the founder's. The schedule
runs only from the default branch, so it starts after the next
`staging`→`main` release. Dependabot PRs were considered and not chosen:
one PR stream per app directory is noise for a solo founder, and its
upgrades can be broader than the advisory needs.

**Verified.** Locally: every npm app passes its threshold; docs-site fails
at moderate and passes at high; `npm audit` works with no `node_modules`.
On this PR, the composer job runs against staging's pre-#474 lock and is
expected to fail. That is the alert path working, and it goes green once
#474 merges.

## 2026-10-17 — Release #476 (`staging`→`main`), verified; docs cleanup

**Release.** #476 merged as `69cf00f` with #473, #474 and #475. The `deploy`
job ran; the box's `current` points at the new release. Checked read-only:
`Composer\InstalledVersions` reports `symfony/http-foundation` v8.1.9, and
`composer.lock` has `laravel/framework` v13.35.0. `api.pekangame.space/api/health`
returned 200, the `pekangame.com` HTML carries the new skeleton markup, and
the `Dependency audit` workflow is active on `main`. The `main` run's
`playwright` job failed on the Google Fonts build flake and passed on a
re-run of the failed job (now PRD §16 item 109).

**FPX test.** Read-only checks found FPX `is_active` since 2026-10-16 16:07
UTC with one storefront order paid at 16:19. The founder confirmed it was
their own test and switched FPX off again (19:16 UTC); all three methods are
off. The PRD §14 "payments off" line stays true, with the test noted, and
item 28's launch trigger has not started.

**Docs cleanup.** PRD §14 headline → release #476. §15's storefront row
now says #474 is released. §16 has three new items: 109 (fonts without
Google Fonts), 110 (security check follow-ups: per-email login throttle,
missing headers, one upload cap) and 111 (Turnstile on logins, needs a
grill). §16's open items 106, 11 and 96 were re-verified: no fix commit,
no Sentry package, OpenWA 0.23.7. A scripted check of all 123 ADR files
against the `adr.md` index found every file indexed and every
last-addendum date matching. The one wording drift (ADR-045's Standing)
was aligned to its file banner.

## 2026-10-17 — PRD §16 item 116: Failed-order card copy matches the voucher

`OrderStatusCard`'s Failed closing line promised "a voucher for the full
amount", but `OrderSettlementService::amounts()` issues
`final_amount − transaction_fee` for a retail order (the gateway fee is
never refunded). The card only reaches retail, non-test orders
(`CustomerNotificationService::inScope()`), so one wording fits every case:
"you'll receive a voucher here for what you paid, excluding the payment fee."
A grep of backend, storefront, admin and portal found no other copy making
the promise. `CustomerWhatsAppOptInTest` now asserts the new wording and
that "full amount" is gone (red first, then green); full suite 2869 passed.

## 2026-10-17 — PRD §16 item 109: fonts without Google Fonts

`admin` (Figtree), `reseller` (Outfit) and `storefront` (Space Grotesk,
Inter, JetBrains Mono) now load fonts with `next/font/local`. The files
are the latin-subset variable woff2 files Google Fonts serves
(`src/app/fonts/*.woff2`, 20–48 KB each), declared with the same weight
ranges and the same CSS variable names, so `globals.css` is unchanged
apart from a comment. No build fetches fonts any more, which removes the
cause of the CI `playwright` boot crash (`Can't resolve
'@vercel/turbopack-next/internal/font/google/font'`). Only latin is
covered, as before (`subsets: ["latin"]`).

Verified: `tsc`, `eslint` and `next build` pass in all three apps. On
`next start` with Playwright, every face reports `loaded` with 0 requests
to Google (JetBrains Mono loads on demand, and the homepage has no mono
text). A storefront header next to prod's shows the same Space Grotesk
glyphs; the layout differs only because the local build had no backend
for the admin-configured logo and nav.

## 2026-10-17 — PRD §16 item 110: security check follow-ups

**Headers.** New `SecurityHeaders` middleware (appended globally in
`bootstrap/app.php`) adds `Strict-Transport-Security: max-age=31536000`
over HTTPS only (no `includeSubDomains`/`preload`, which would bind every
`pekangame.space` host), `Referrer-Policy: strict-origin-when-cross-origin`
and `Permissions-Policy: camera=(), microphone=(), geolocation=()`. It is
done in Laravel, not in Forge's nginx (which already sends
`X-Frame-Options` and `nosniff`), so it ships through staging → main with
no hand-edit on the box. `SecurityHeadersTest` covers HTTPS, plain HTTP and
an error response. Admin, storefront and portal `next.config.ts` add the
same two policies, the portal gains `nosniff`, and all three set
`poweredByHeader: false`. No frontend uses camera, microphone or
geolocation. Checked with `curl -I` against `next start` builds of all
three.

**Upload cap.** `CorrectSupplierTransferRequest`'s `receipt` gets
`max:5120`, like its three siblings (red→green test). Every other
FormRequest with a file rule already has a `max:`.

**Login throttle: no change.** The audit said `/login`, `/login/mfa` and
the affiliate login are throttled per IP only. They are not:
`App\Support\AccountLoginThrottle` (ADR-019 2026-09-29 addendum, Wave 3
PR-B) locks an email for 15 minutes after 10 failures from any mix of IPs,
on admin login, MFA code, step-up and affiliate login, with tests in
`AuthControllerTest` and `AffiliateAuthControllerTest`. That is tighter
than the 10/min + 30/hour bucket agreed before this was found (at most
~40 guesses an hour instead of 30 an hour plus a separate per-minute
limit), so no second limiter was added.

Full backend suite 2872 passed; `tsc`, `eslint` and `next build` pass in
the three apps.

## 2026-10-17 — PRD §16 item 115 (part): cost readers use the real cost

With ADR-111 on, a Delivered order's `platform_profit` and ledger credit
use the real supplier cost, but two readers still showed
`orders.cost_price` (the checkout estimate) for Delivered orders while
already using `Order::effectiveCostPriceSen()` for settled partial ones:

- `CustomerAnalyticsService::profitAnalysis()` (Customer Detail). ADR-049
  §11 already names `effectiveCostPriceSen()` as this panel's cost; the
  Delivered branch was a leftover from 2026-08-28, kept through item 103.
- `TransactionRegisterService::orderRows()` (screen and CSV, one `rows()`
  path). Now eager-loads `deliveryLegs.componentPackage:id,cost_price`,
  `voucher` and `voucherRedemption`, which the method reads for a combo or
  a partial, so no N+1.

Both now use `effectiveCostPriceSen()` for every realised order. Already
correct before: Order Detail and the Orders workbook export. Left open:
`MonthlyAccountingSummaryService::cogs()`. It also feeds the FX variance
true-up, so switching it moves that line by the same amount and operating
profit stays the same; which presentation the accountant wants is an item
81 question. PRD §16 item 115 now covers only COGS.

Prod scale (read-only tinker, 2026-10-17): 29 realised orders, all
Delivered; 4 have a real cost that differs (2 of them combos, 2 customers),
RM59.23 estimate vs RM59.49 real, so RM0.26 in total. Two red→green tests;
full suite 2874 passed.

## 2026-10-17 — Docs audit: outdated and contradicting facts

Three read-only forks (PRD; ADR index and files; AGENTS/README/security
docs) checked every doc against git, code and prod; the top findings were
re-checked by hand. Fixed in one docs PR:

- **Money/design.** ADR-125 decision 24's `COALESCE(real_cost_price_sen,
  cost_price)` for the report view was wrong for combos (real cost lives on
  the legs) and for an unused stored real cost; a 2026-10-17 addendum
  requires it to mirror `Order::effectiveCostPriceSen()`. The live Report
  Assistant prompt has the same gap: new PRD §16 item 118. ADR-050 gets an
  addendum for item 115's Supplier Cost. ADR-107's Standing now says what
  ADR-111 and ADR-094 replaced.
- **Status.** PRD §14 "waiting on staging: docs only" listed #478–#482;
  §15 Reports said "on staging" for #454/#455 (live via #462); §15
  Accounting now says the drift chip watches only a supplier with
  `drift_threshold` set (DigiFlazz has none); README notes payments are
  off and that build-log starts 2026-10-15.
- **Admin auth.** README, root `AGENTS.md`, `reseller/AGENTS.md`, PRD §5
  and AUTH-2 still described admin auth as a bearer token; it has been a
  cookie session since ADR-121. `foundation-security.md`'s token-expiry line
  cited a removed test; it now covers the session limits, and gains lines
  for `AccountLoginThrottle` and the #481 headers (missing it is why the
  110 audit misreported the throttle).
- **Spec.** PRD MID-8/§7.1 and the `Validator` entity now describe
  `PlayerValidatorProfile`/`PlayerRegionMapping`; §8 Order lists
  `real_cost_price_sen` and how `platform_profit` is recomputed; §9 says the
  storefront is English-only, with more languages and currencies recorded as
  a long-term expansion item (§16 item 117, founder 2026-10-17); glossary
  gains the ADR-124/125/126 terms before 112–114 are built.
- **Smaller.** Dropped-item references (89, 58), the third UptimeRobot
  monitor, "Phase 2" for live Affiliate storefronts, the §16 intro date,
  `AGENTS.md`'s non-existent "Buildable when triggered" heading and its
  `ignoreCommand` pointer (the logic is `scripts/vercel-ignore-build.sh`),
  the admin's local API URL, docs-site's `DOCS_SITE_URL` (already set),
  and cross-reference addenda or banner fixes for ADR-022, 026, 033, 071,
  076, 083, 093 and 121.

Left as is: the "(built YYYY-MM-DD)" suffix on 20 old ADR titles (dated
history) and ADR-025's Gamevion wording (history; the guard is
supplier-agnostic in code).

## 2026-10-17 — PRD §16 item 118: Report Assistant margin uses profit, not the estimate

The plan prompt (`ReportAssistantService::schemaDescription()`) described
`llm_report_orders.cost_price` as "what this order cost the platform" and,
under the catalog view, told the model to use it "for a specific past
order's actual margin". With ADR-111 on, `cost_price` is the checkout
estimate; the view's `platform_profit` already comes from the ledger and
carries the real cost. The prompt now calls `cost_price` the checkout
estimate and sends a past order's margin to `platform_profit` (and
`affiliate_profit`). Prompt only: no view change, so no migration (the
`effective_cost_price` column stays ADR-125's, per its 2026-10-17
addendum). One red→green prompt test; full suite 2875 passed.

## 2026-10-17 — Release #485 (`staging`→`main`), verified

Merged as `d030484` with #477–#484 (items 116, 109, 110, 115 part, 118 and
three docs PRs); no migration, no config or env change. The `main` CI run
passed every job, `deploy` included. Checked live: `current` →
`releases/80455580` at `d030484`, with `SecurityHeaders` in
`bootstrap/app.php`; `api.pekangame.space` sends HSTS, `Referrer-Policy` and
`Permissions-Policy`; `pekangame.com`, `admin.` and `reseller.` send
`Referrer-Policy`, `Permissions-Policy` and `nosniff` with no
`x-powered-by`; the storefront HTML references only its own
`/_next/static/immutable/media/*_latin*.woff2` files and nothing from Google;
`/api/health` 200; the released `OrderStatusCard`, Report Assistant prompt and
Transaction Register code are on the box.

**Gotcha: Forge deploys before CI.** The release directory was built at
09:20:17 UTC, 10 seconds after the 09:20:07 merge and while
`backend-concurrency` was still running; the later CI `deploy` job made no
new release. #476 shows the same gap. ADR-066 says Quick Deploy stays off,
so the CI gate isn't working on the ADR-114 box. Recorded as PRD §16 item
119 (founder, Forge UI).

## 2026-10-17 — Item 106: price-sync `partial` status + stale-supplier email (`staging`)

**Why:** `SyncSupplierPricesJob` marked a run `failed` only when every
supplier failed. One supplier failing beside a good one left the run
`success` with an empty `error_message`, and the failure was visible only in
`laravel.log`. Prod log from 2026-09-27 showed 23 hidden supplier failures:
20 Digiflazz `[83]` (all before item 99's fix deployed 2026-10-15 09:08 UTC,
none since) and 3 Gamevion `cURL 28` (run 1494, and 2305–2306 on
2026-10-16). The longest consecutive failure was 2 runs. Gamevion's failures
are outages, not slowness (2026-10-16: 1,252 good `listProducts` calls,
p50 0.95 s, p99 5.9 s; every failed attempt got 0 bytes in 10 s), so the
10 s timeout stays.

**Shipped (ADR-015 2026-10-17 addendum):** a `partial` run status with
`stats.failed_suppliers` ({slug, error}), shown on admin Price Sync as a
warn tag plus one line per failed supplier; `suppliers.last_price_sync_at`
(backfilled to the deploy time) and `price_sync_alerted_at`;
`app:check-price-sync-staleness` in `TaskSchedule` (routine,
every 15 min, inline in the scheduler) emailing all admins once when a
supplier passes `price_sync_stale_alert_minutes` (new Middleware →
Suppliers field, blank = 90, 0 = off) and once on recovery.

**Design change after a stress test:** the grill first chose "N consecutive
failed runs" counted from run history. Rejected before any code: Sync Now
clicks and the interval env change what N means, a `failed()` run would
have to claim every supplier failed, a check inside the job never runs with
the queue down, and history-derived "already alerted" races a concurrent
manual run.

**Verified:** 9 new tests (job: partial, success, failed list; command:
threshold, default, off, recovery once, off-doesn't-fake-recovery,
inactive/unconfigured skipped, never-synced) plus the full suite green;
admin `tsc` and lint clean; local production admin build with a seeded
`partial` run: Last Sync card and Sync History show the warn tag (text
`#8a5100` on `#fdf1dc`) and the failed supplier's error. Local dev DB
migrated with plain `php artisan migrate`.

## 2026-10-17 — Item 119 cause: CI deploy hook pointed at the old box (fixed, verify on next release)

Forge's dashboard showed every release on the lwf site deployed "via Push to
deploy", and a matching failed "via Custom" deployment on the old
`pekangame-prod` site (Forge server 1247825, Disconnected; its droplet was
destroyed 2026-09-30, SSH to 157.245.203.250 unreachable). `gh secret list`:
`FORGE_DEPLOY_HOOK` last set 2026-09-02, before the ADR-114 move. So CI's
`deploy` job had been POSTing to the dead site since 2026-09-25, and the new
site deployed each push by itself before CI finished.

**Production change (founder, 2026-10-17 ~12:11–12:15 UTC):** re-set
`FORGE_DEPLOY_HOOK` from the clipboard (`pbpaste | gh secret set`; the first
attempt through `!` had no prompt and stored an empty value, corrected a
minute later). Checked without printing it: one line, a Forge deploy URL for
site 3397779 (lwf `api.pekangame.space`). Then turned the site's "Push to
deploy" off (checked in Forge: switch off). Before: Push to deploy on,
secret → old site. After: Push to deploy off, secret → lwf site.

**Cleanup, same session (founder):** deleted the unused
`DIGITALOCEAN_ACCESS_TOKEN` repo secret (`gh secret list` now shows only
`FORGE_DEPLOY_HOOK`) and the orphaned `pekangame-prod` server from Forge
(Forge now lists only `pekangame-prod-lwf` and `nakhoda-prod`). This also
closes ADR-114's "remove the orphaned Forge server entry" hygiene note.

**Left (PRD §16 item 119):** verify the next release is a single "via Custom"
deployment after the CI `deploy` job. ADR-114 got
a 2026-10-17 addendum with the box-move checklist; the `ci.yml` header
comment now names the setting "Push to deploy".

## 2026-10-17 — OpenWA: phone notifications restored

The founder noticed that both WhatsApp numbers got no phone notification
for incoming messages while they were connected to OpenWA. Notifications
came back once a session was disconnected. The cause is Baileys'
`markOnlineOnConnect`, which defaults to true. The linked device announces
itself "online", and WhatsApp then stops pushing to the phone (OpenWA
#871). Our backend sends no read receipts.

**Production change (founder, 2026-10-17 ~12:31 UTC):** added
`BAILEYS_MARK_ONLINE_ON_CONNECT=false` to `~/OpenWA/.env` (backup
`.env.bak-20261017`), then restarted the daemon from Forge. Before: the
line was only a comment, so the default (true) applied. After: line 795
has `=false`, the process started at 12:34:00 UTC, `reseller-bot` was
ready at 12:34:09 and `customer-support` at 12:36:01. The founder
confirmed the phones get notifications again. Recorded in the ADR-114
2026-10-17 addendum, along with the open `SIMULATE_TYPING` note.

## 2026-10-17 — Release #490 (`staging`→`main`), verified; item 119 closed

Merged as `4ced680` at 12:47:37 UTC, with #487 (item 106) and docs #486,
#488, #489. The `main` CI run passed every job. `deploy` ran
12:54:40–12:54:43. Checked on the box: release `80463744` was built at
12:54:49, and `current` points to it at `4ced680`. No release directory
appeared at the merge (checked 12:47:53). The migration
`2026_10_17_120000_add_price_sync_health_to_suppliers` shows Ran (batch 44).
`schedule:list` shows `app:check-price-sync-staleness` every 15 minutes.
`/up` returns 200.

**Item 119 closed.** The deploy went through the CI gate: one release,
built after the `deploy` job. Before 2026-10-17 the box built 10 seconds
after each merge. A directory `80314022` showed up for a moment at 12:55:15.
It was the oldest release being pruned by Forge (five are kept), not a
second deploy.

**Left for item 106:** the first real `partial` run or stale-supplier email
in prod is its live check.

## 2026-10-17 — Item 111 parked; a password lockout now alerts

The grill on item 111 (Turnstile on the admin and portal logins) found it
isn't needed yet, and the founder parked it in group D. Prod: 1 admin
(MFA active) and 3 invite-only portal accounts. Since 27 Sep the logs show
33 failed logins: 32 from the founder's IP and 1 from an AWS Singapore IP
on 9 Oct. The founder had asked about the free plan's limits. Checked
against Cloudflare's docs, they don't block it: 20 widgets, 10 hostnames
per widget, unlimited challenges, and `admin.`/`reseller.` are already
covered by `pekangame.space` on the ADR-123 widget.

**Gap found and fixed.** `AccountLoginThrottle::recordFailure()` returns
true on the failure that locks the account. Only `AdminMfa` (wrong codes)
used that return value. The admin and portal password logins ignored it,
so a password lockout only wrote a log line. Now both email every admin
(`BackupFailureAlerter`, area Security, "Account locked", with the email
and last IP). Only real accounts alert, so an attacker cycling made-up
emails can't flood the inbox. Test-first: 4 new tests in
`AuthControllerTest` and `AffiliateAuthControllerTest` (red → green).
Full suite 2888 passed. ADR-019 2026-10-17 addendum.

## 2026-10-17 — Item 115: Monthly Summary COGS at funded cost (on `staging`)

The planned fix was to switch COGS from the checkout estimate to ADR-111's
real cost. The founder asked for an accounting check first, and the check
changed the fix. ADR-111's real cost is the supplier's IDR price at the
**market** rate on delivery day. The Digiflazz deposit can't be withdrawn
(founder), so it is a non-monetary prepayment, and under IFRIC 22 and IAS 2
an order costs its drawdown × the MYR actually paid per IDR.

Operating profit was already that number, by accident: COGS cancelled
against the FX true-up. That hid three gaps: a supplier with no rate, an
order with no drawdown, and drawdowns timed by `created_at`.

**Built:**
- `FundedCostOfSales` costs `RecognisedRevenue`'s orders (new
  `recognisedOrders()`, so cost follows revenue) unit by unit: plain orders
  and delivered combo legs. Funded units are summed per supplier and rounded
  once. IDR is valued at the month-end blended rate, MYR at face value.
  Anything else falls back to its own cost and is counted on the page.
- The true-up left operating profit. The bridge lines are "COGS at
  per-order cost" + "Deposit rate vs market rate" = COGS (tested exactly),
  plus "Supplier drawdowns not in this month's COGS".
- `weightedAverageRate()` moved to the new class. `CashPositionService` now
  depends on it.
- Per-order cost labels say "delivery rate": the Register screen and CSV,
  Customer Detail, and the Orders export.

**Stress test before build.** It found four gaps in the first design, all
fixed: per-order rounding, unsettled partials, the bridge vs the Register,
and no check that every drawdown is counted. Several scenarios were checked
safe: sandbox orders (`FakeSupplierAdapter` price null, so no drawdown),
resend, failed legs, pricing bases. My earlier claim that sandbox drawdowns
polluted profit was wrong.

**Checks.**
- Tests: 11 new (`FundedCostOfSalesTest`, red → green), 2 superseded
  true-up tests removed, full suite 2897 passed. Admin: tsc, lint, build
  and node tests all pass.
- Browser: Monthly Summary on a local production build showed the new
  lines. The fallback warning wasn't seen live: it needs an order with no
  drawdown, and the dev DB has none.
- Read-only prod run of the new class: September and October operating
  profit unchanged (RM4.64, RM3.64). COGS RM151.86 and RM169.69.

**Corrections to what I told the founder:**
- I had said September's funded COGS would be about RM149.34. It is
  RM151.86: the deposit rate cost more than the market rate, not less.

**Left:**
- Release. Then reopen and re-close September (§16 C, item 115).
- Item 121: estimated combo snapshot.
- Item 120: keep CHIP files.

## 2026-10-17 — Item 121: estimated combo cost no longer follows the catalog (on `staging`)

The addendum decided on a checkout snapshot, but the trace before coding
showed it was wrong. A delivered combo's profit is set from each leg's
catalog cost **at delivery** (`resolveComboOutcome()`, ADR-107), and a
settled partial's at settlement, so the snapshot can differ from what the
profit used. The founder agreed to read the cost back from the profit
identity instead, `revenue − platform_profit − affiliate_profit`, for a
realised combo with no real-cost basis. Unrealised combos keep the delivered
legs' catalog cost.

**What changes:** reader only, `Order::comboCostReconciliation()`. Pricing,
delivery, profit and the ledger are untouched.

**Tests:** 3 new in `OrderTest`, red → green. One first version passed by
coincidence (its numbers equalled the catalog) and was rewritten to
separate them. Full suite: 2900 passed.

**Prod (read-only):**
- #7: 186 (was 184 live) and #28: 1065 (was 1074). Both equal their
  profit identity.
- September's per-order cost line drops by RM0.07. COGS and operating profit
  are unchanged.

**Left:** item 122, a stored per-leg cost for the leg-level COGS fallback.

## 2026-10-17 — Release #495 (`staging`→`main`), verified; September re-closed

Merged as `70a5989` with #491–#494: the lockout alert, item 115 (funded-cost
COGS), item 121 (combo cost) and docs. No migration, no config change. The
`main` CI run passed every job, and `deploy` ran 15:54:09–15:54:11. The box
built release `80471303` at 15:54:17, after the gate, and `current` points
to `70a5989`. `/up` returns 200.

Checked live (read-only tinker):

| | September | October |
|---|---|---|
| COGS | RM151.86 | RM169.69 |
| Per-order cost | RM150.61 | RM166.54 |
| Deposit vs market | +RM1.25 | +RM3.15 |
| Fallback orders | 0 | 0 |
| Drawdowns not in COGS | 0 | 0 |
| Operating profit | RM4.64 (unchanged) | RM3.64 (unchanged) |

Orders #7 and #28 cost 186 and 1065.

**Production change (founder, from the admin):** September 2026's month
close was reopened and closed again. Close #1 was voided at 15:55:59 UTC
with a reason, and posting #28 (−RM4.64) reverses its allocation #25.
Close #2 was made at 16:22:52 with the same allocation (RM4.64 to Company
Savings, posting #29), the same cash (RM80.43, "Held by Farres (mixed)";
the founder re-checked it against the September statement before closing),
the same gap (−RM10.94) and the same note. The snapshot holds COGS RM15186
sen, and the old FX key is gone. September drift is 0 and October's prior
adjustment is 0. The founder asked whether the cash should be RM60. No
record anywhere shows RM60, and RM60 would have made the gap −RM31.37.

Not checked live: the lockout alert email. It has test coverage, and the
first real lockout will be its live check.

## 2026-10-18 — Transaction Register: order profit corrections get their own row (§16 item 123)

**Why.** A ChatGPT review of the admin screens flagged order `PG-KWKBUHNDMKQT` (id 15): Register Net −RM0.10, Orders +RM0.27. `TransactionRegisterService::orderRows()` read the platform `order_profit` rows with `pluck('amount', 'reference_id')`, which keeps only the last row per order. Order 15 has the automatic +37 credit and the manual −10 ADR-105 correction (ledger entry 45).

**Checked before the fix (prod, read-only).** All 37 paid non-test orders: the Register matched the ledger for 36. Order 15 was the only order with two platform `order_profit` rows, and entry 45 the only manual ledger row on an order. The column `platform_profit` matched the ledger on every delivered order. No delivered order lacked a credit, and no undelivered order had one. Every other `pluck`/`keyBy` in `app/` either aggregates first or keys on a unique column. Vouchers 1, 2, 4 and 5 each have two `voucher_issued` rows netting 0: the item-79 reversals (2026-10-13), which nothing reads per voucher. September's close (`operating_profit_sen` 464) equals the live figure.

**Shipped (founder chose option B, ADR-083 2026-10-18 addendum).** The order row's Net = the automatic credit (`reason IS NULL`, summed). A new `orderProfitCorrectionRows()` adds one `order_profit_correction` row per manual platform correction, dated at its own `created_at`, with reference = order number, and the ledger entry id and reason in the description. Test orders are excluded. Admin: a new type label (warn tag) and filter option. Tests: `TransactionRegisterOrderProfitCorrectionTest` (5) covers order 15's shape, the period split, the ledger invariant, test-order exclusion, and the CSV row and footer. Full suite 2905/2905. Admin tsc, lint and build are clean.

**Live check.** The new queries were run read-only on prod: 37/37 reconcile, order 15 = +37 (17 Sep) and −10 (20 Sep), September platform net 705 sen. Browser: a built admin on `localhost:3000` against `serve --no-reload` on a scratch copy of the dev sqlite, with a −10 correction added to the copy only. The row showed with its label, the type filter returned it alone, a 11 Sep date filter showed only the order row (+RM10.52), and the CSV had `-0.10`. The copy and the throwaway admin were deleted afterwards.

**Gotcha.** `LedgerEntry` refuses updates (append-only guard), so a test can't back-date a row with `forceFill()->save()`. The test uses `travelTo()` around the create instead.

## 2026-10-18 — Test suite audit: route-table access test, guard-test cleanup, oversized files split

**Why.** The founder asked whether the ~2900 backend tests, written feature by feature since the start, were solid or duplicated.

**Audit (read-only).** 2905 tests in 308 files, 82 s for the whole suite. 0 test bodies identical. 96 tests have the same shape with different literals; all are legitimate (different routes, suppliers or edge cases). 0 tests without an assertion (9 looked like it but verify through `Log::shouldHaveReceived`). 136 tests only asserted a 401/403 from route middleware on one route. Six files were over 1000 lines.

**Shipped: #498 (merged to `staging`).** `RouteAccessTest` pins who can reach every `/api` route: 42 public, 5 admin routes with no role check, 2 portal session routes, 72 routes open to a regular admin (the rest are `super_admin`), the portal and API-key guard per prefix, and `auth:sanctum` + `EnsureAdminSessionFresh` on every admin-role route. Unsigned `storage/{path}` requests are refused. Checked by breaking real routes on purpose (an unguarded route, a group widened to `admin`, a portal route outside its group): each failed it. 110 guard-only tests were removed. Kept: refusals that come from controller logic, the membership switch, a wrong-brand token, an invalid API key, and one `EnsureAccountType` refusal in each direction. Also removed 2 dead helpers and the scaffold `Unit/ExampleTest`, and renamed `Feature/ExampleTest` to `RootIdentityTest`. ADR-072 2026-10-18 addendum. Route audit: all 49 `/api` routes outside the guard groups are deliberate; no route is missing a guard.

**Shipped: this PR.** The six >1000-line files are split by feature: Admin `OrderControllerTest` → 5 files, `OrderFulfillmentServiceComboTest` → 4, `CheckoutControllerTest` → 7, `OrderFulfillmentServiceTest` → 3, `OrderResendServiceTest` → 3, `ResellerBotServiceTest` → 4. Shared setup and helpers moved to one trait per original file in `tests/Support/` (the `PartialComboOrders` pattern), so no helper is copied. The move was done by a script that fails on any unmapped test. No test body changed: the set of test names is identical before and after, and the suite stays 2801/2801. The largest file is now 699 lines. The 600–940-line files left (one adapter, one webhook, one controller each) are single-subject and were left whole.

**Checked and not changed: tests that look duplicated across layers.** 40 controller/service pairs with similar names were read side by side. None is a true duplicate. Controller tests check the HTTP wiring: parameters, the 422 status, and that no job is queued when a rule fails. Service tests check the logic. Some rules exist only in the controller (`retryDelivery`'s guards, refunded-to-wallet, voucher-restored), so those controller tests are the only coverage of that rule. Name matches across the portal, REST API and WhatsApp bot are different channels, each needing its own test. Nothing was removed.

**Gotcha.** `git rm`/`git mv` staged before a path-limited `git add` + commit end up in that commit. The branch's commits had to be rebuilt (it was not yet pushed).

## 2026-10-18 — §16 item 120: keep accounting source files

**Why.** LHDN expects payment-gateway statements and original receipts to be kept for seven years. The CHIP settlement `.xlsx` was parsed and discarded, and receipts had no dated layout.

**Grill + stress test (founder).** The founder chose: refuse a file with nothing new (broadened from "byte-identical only" once the stress test showed a CHIP re-export has different bytes), folders by `date_to`, an "Attach original file" for the four old settlements, and keep a receipt a correction replaces. The stress test found four gaps (ADR-083 2026-10-18 addendum, "keep accounting source files"):
- orphaned files from supplier-transfer and wallet-credit saves;
- a correction deleting the receipt it replaced;
- a settlement row created outside its transaction, leaving an empty `pending` row on re-upload;
- a 500 on an unreadable file.

**Checked first (prod, read-only).** `accounting_disk` and `wallet_receipts_disk` are `r2_accounting`. The bucket holds 29 files: 26 envelope receipts and 3 supplier receipts. 4 settlements (#1–#4, 3 Sep – 6 Oct), none with a file. The app's R2 token can't read the bucket's lifecycle rules (`AccessDenied`, least privilege), so the founder checks them in Cloudflare.

**Shipped.**
- `AccountingFileStore::storeThen()`: the one store for CHIP settlements, supplier transfers (and corrections), envelope postings and manual wallet credits.
- Migration `payment_settlements.file_path` + `file_sha256` (unique).
- `SettlementReconciliationService::ingest()` takes the `UploadedFile`, refuses a file with nothing new, and keeps the original only when the whole ingest commits.
- `attachOriginal()` accepts only an exact date + total match.
- `GET`/`POST /api/accounting/settlements/{id}/file`.
- Admin: download / attach on the settlement detail page and updated upload copy.

Tests: 4 + 8 new, 4 updated. Full suite 2816/2816. Admin tsc, lint and build are clean.

**Live check.** A built admin on `localhost:3000` against `serve --no-reload` on a copy of the dev DB, covering upload, same-file refusal ("…already reconciled (settlement #3)…"), download as `settlements-20261016.xlsx`, a refused attach of a non-matching file, and an accepted attach of the matching one. Screenshots checked. The copy was deleted afterwards.

**Gotchas.**
- The first full test run wrote 10 real `.xlsx` files into `storage/app/private/accounting/chip-settlements/`. `PaymentSettlementControllerTest` uploads through the endpoint without `Storage::fake()`, which used to be harmless because upload kept nothing. Fixed in that test's `setUp`, and the stray files were deleted. A second full run wrote nothing.
- `UploadedFile::hashName()` is cached per instance. A test reusing one instance for two uploads gets the same storage path, so the failed second write's cleanup deleted the first file. Real requests always carry a new instance; the test now uses two.

**Founder follow-ups.** Check R2 lifecycle rules. After release, attach the originals of settlements #1–#4.

## 2026-10-18 — Release #500 live (items 123, 120, test audit)

`staging`→`main` at the founder's request. #500 was opened, then held while item 120 was grilled and built (#501), then merged as `e127dcb` once CI was green on the head that included #501. The CI `deploy` job passed, and the prod `current` is release `80479848` at `e127dcb`. Migration `2026_10_18_120000_add_original_file_to_payment_settlements` ran (batch 45). `/up` 200.

Checked live (read-only):
- Transaction Register: PG-KWKBUHNDMKQT shows `order` +37 on 17 Sep and `order_profit_correction` −10 on 20 Sep.
- `payment_settlements` has `file_path`/`file_sha256`, and #1–#4 have no file.
- September's close: stored 464 = live 464.

Founder follow-ups: check the R2 lifecycle rules on `pekangame-accounting`, and attach the originals of settlements #1–#4 from CHIP's dashboard.

**2026-10-18, later:** the founder checked the R2 bucket `pekangame-accounting` in Cloudflare. Its only lifecycle rule is the default "abort incomplete multipart uploads", which never deletes stored objects, so the seven-year retention holds. Remaining follow-up: attach the originals of settlements #1–#4.

**2026-10-18, later:** the founder attached the original CHIP files to settlements #1–#4 (04:43–04:45 UTC). Read-only check on prod: every `file_path` exists on `r2_accounting` (#1–#3 under `2026/09`, #4 under `2026/10`), the SHA-256 of each stored file equals `file_sha256`, no hash appears twice, and status `matched` and net figures are unchanged (RM33.14, RM457.00, RM36.50, RM4.25). Item 120 has no follow-up left.

## 2026-10-18 — §16 item 112: customer account credentials base (ADR-124)

**Why.** Login Top-up (113) and Joki (114) need the customer's game login, the first password the platform would hold. ADR-124 (accepted 2026-10-17) designed the storage; this builds it alone first, so the money-path work in 113 stays a separate PR.

**Pre-build trace + stress test (founder asked for both).** Every decision was checked against code and prod (read-only). Five corrections, recorded as the ADR-124 2026-10-18 addendum:
- A failed payment is not final: `OrderPaymentOutcomeService` takes a Paid after a Failed into NeedsReview. Wiping at `payment_status = failed` would wipe a paying customer's login. Now: not paid (pending or failed) for H hours. CHIP purchases are `due` +30 min with `due_strict`, so H ≥ 1 h is safe. Prod: 5 failed payments, each marked 30–45 min after creation, no late Paid yet.
- "Closed for G hours" had no closing-time column (compensation time is spread over three tables). The sweep stamps `closed_at`. Closed is final: Delivered has no exit, and compensated orders are refused in `fulfill()` itself.
- The D ceiling counted from order creation would wipe a login 15 minutes after a super_admin replaced it on a still-open order. It now counts from `secrets_set_at`.
- "Compensated" as a query existed only as an inline copy in `scopeNeedsAction`. Extracted `Order::scopeCompensated()` (+ `scopeClosed()`), NeedsAction uses it, and `OrderCompensationScopeTest` holds it equal to `isAlreadyCompensated()`.
- `credential_reveals` takes `actor_type`/`actor_id` now, so ADR-125 PR 2's supplier link needs no audit migration.
Also checked and safe: no endpoint returns `PlatformSettings` publicly; `updatePlatform` uses `validated()`, so the unguarded form can't change the policy; a never-run critical task is seeded on first health check (ADR-122 decision 8), so the deploy won't page UptimeRobot; the founder's prod super_admin has MFA active. Item 122 checked at the same time: 5 of 5 delivered combo legs have a drawdown, so its trigger hasn't fired.

**Shipped.**
- Migrations: `order_credentials` (FK cascade, since the sandbox deletes test orders), `credential_reveals` (FK null on delete), five policy columns on `platform_settings` (consent text written out in the migration).
- `OrderCredential` (`encrypted:array` secrets, `$hidden`), `CredentialReveal`; no relation from `Order`.
- `app:wipe-order-credentials` every 15 min, critical; plain `UPDATE`s, never decrypts. `app:prune-credential-reveals` daily, routine.
- `POST /api/orders/{order}/credentials/reveal` and `PUT /api/orders/{order}/credentials`: super_admin, `mfa.stepup`, reveal throttled per admin (`credential-reveal`, limit from settings), `Cache-Control: no-store`, audit row on every success, sandbox orders 404, replace refused (409) on a closed order.
- `PUT /api/settings/customer-login-policy` behind step-up. Order detail gets `customer_login` (never a secret).
- DB dumps exclude `order_credentials` on both connections.
- Admin: Order Detail "Customer Login" card (reveal 30 s + copy, replace), Settings → Customer Login Policy tab.
- Stale "five dangerous actions" / `StepUpRoutesTest` comments fixed in `routes/api.php` and `TestCase`.

Tests: 4 new files (20 tests), `AdminMfaStepUpTest` pin updated. Full suite 2836/2836; the new tests plus `OrderTest` also pass on MySQL (49/49). Admin tsc, lint, unit tests and build are clean.

**Live check.** A built admin on `localhost:3000` against `serve --no-reload` on the local dev DB, with a throwaway MFA super admin and two seeded logins. Login with TOTP → Order Detail card (password hidden) → Reveal asks for step-up → value shown with Copy → Replace saves and hides it again → Settings tab saves D = 10. DB afterwards: secret replaced and stored as ciphertext, two audit rows (reveal, replace) with IP. Contrast from `getComputedStyle`: light `rgb(27,26,23)` on white; dark muted `rgb(189,183,173)` on `rgb(26,25,23)` (~9:1). At 390 px the policy tab has no horizontal scroll once loaded at that width (157 px only when a desktop sidebar is left open by resizing, an artefact of the resize). Test data, the admin and the D change were removed afterwards.

**Gotcha.** A second Playwright login inside the same 30-second TOTP window fails: the MFA replay guard (`mfa_last_timestep`) rejects the reused code. Wait for the next window.

**Next.** Item 113 PR 1 adds the checkout fields that write a login.

## 2026-10-18 — §16 item 113 PR 1a: supplier driver and every API-only reader (ADR-125)

**Why.** Login Top-up needs suppliers with no API. Before a single manual supplier can exist, nothing built for API suppliers may touch it: it would crash the critical balance refresh (`/api/health` 503), email "price sync stale" every run, 500 the "Check supplier" button, and show a balance of 0.

**Stress test first (founder asked).** Three read-only forks (checkout and pricing; fulfillment and ledger; downstream readers) re-traced ADR-125 against current `staging`; I re-checked the top findings and ran the prod queries myself. Twelve gaps, none in the ADR, recorded as the ADR-125 2026-10-18 stress-test addendum (decisions 25-35). The founder chose: split PR 1 into 1a/1b, ship both funding modes, mask the login identifier. Prod: 2 API suppliers, 37 of 38 games with `reseller_code`, 18 with the player validator on, 0 orders Pending/NeedsReview/Processing, 5 Digiflazz transfers, `manual_confirm` never used.

**Shipped (1a).**
- Migration `suppliers.driver` (NOT NULL default '', backfilled `= slug`; `->change()` would rebuild the sqlite table and break the `llm_report_catalog` view) and `funding_mode`. The model fills `driver` from `slug` on create.
- `SupplierAdapterFactory::for(Supplier)` resolves `supplier-adapter.{driver}`. Its 9 callers, the replay rule (now takes the `Supplier`), `SupplierConfigSchema` (keyed by driver, with a `manual` schema: `reply_sla_minutes`), the sandbox binding and the test commands all read `driver`. `ManualSupplierAdapter` refuses every call.
- `CreateSupplierRequest`: an API driver's slug is its driver (set on the server); only `manual` names its own slug, is MYR, and picks a funding mode.
- `Supplier::isApiBacked()`, `isSyncable()`, `pendingPolicy()`, `displayBalance()`, `isPayAfter()`; `Order::fulfilmentSupplier()` (replacing three copies) and `isStalePending()`.
- Readers: price sync, its schedule gate and item 106's staleness alert use `isSyncable()`; product sync and balance refresh use `apiBacked()`; `SupplierDeliveryCheckService::canAsk()` gates the admin button, the reconciler and `check()`; refresh-balance and the developer tester refuse a manual supplier; the reconciler and Dashboard share `pendingPolicy()` (fixes the old Dashboard/reconciler drift).
- Dashboard: displayed balance, wallet coverage sums API suppliers only, a `manual_delivery_without_drawdown` item.
- Accounting: `weightedAverageRate()` is 1.0 for every MYR supplier (COGS, supplier corrections and the month-end position share it); the cash position splits each supplier's balance into `supplier_prepaid_sen` and a new `supplier_payable_sen` (gap unchanged; old closes read it as 0); `recordOrderDrawdown()` skips sandbox orders.
- Admin: add/edit a manual supplier (type, slug, MYR, funding mode), one balance label everywhere ("Owed: MYR 65.00" for pay-after), no Refresh for manual, "manual" instead of a connection state, payable row in the cash equation, the new briefing item, top-up vs payment wording.

Tests: 2 new files (14 tests); 3 updated for the renamed endpoint, the `driver` field and the wider health payload. Full suite 2850/2850. Concurrency suite on MySQL: 31/35, all money tests green (including `FinalizePendingDeliveryConcurrencyTest`). The 4 `BackupRestoreMysqlTest` failures are `mysqldump: command not found` on this machine; CI runs them. Admin tsc, lint, unit tests and build are clean.

**Live check.** A built admin on `localhost:3000` against `serve --no-reload` on the local dev DB, with a throwaway MFA super admin. Added "Roblox Ali" (manual, pay after) through the modal with step-up: slug suggested `roblox-ali`, currency locked to MYR. The card shows "manual", "Manual · Pay after delivery" and no Refresh button; Balance shows "Funding ledger". After a −65 ledger row: "Owed: MYR 65.00" on the card, Balance and Dashboard. `app:refresh-supplier-balances` and `app:check-price-sync-staleness` ran with it present (exit 0, skipped). Two issues found and fixed in the live check: the card showed "connected" (the API circuit state), and the Dashboard showed "≈ RM -65.00" beside "Owed". Test data and the admin were removed afterwards.

**Gotchas.**
- A killed MySQL run leaves the `llm_report_*` views (backend AGENTS.md gotcha 8); drop them before the concurrency suite.
- Next's router ignores a manual `history.pushState` to another route in Playwright. After login, `page.goto` keeps the session.
- First CI run failed `playwright` (resend and checkout ended `needs_review`: "No SupplierAdapter implementation for driver:"). The model filled `driver` from `slug` in a `creating` hook, but every seeder runs `WithoutModelEvents`, so the e2e supplier was stored with `driver = ''`. Fixed at the read: `Supplier::driver()` returns the slug when the column is blank, which covers seeders and raw inserts too. A regression test proves it red without the accessor.

**Next.** PR 1b (login game, manual packages, checkout, Manual Queue, masking, copy).

## 2026-10-18 — Docs audit after items 112 and 113 PR 1a

PR #507 merged to `staging` (second CI run green after the `driver` fix). The founder asked for every doc to be brought in line before 113 PR 1b starts in a new session. Checked against code and prod (read-only): `origin/main` = `e127dcb` (#500) on the box (release `80479848`); `staging` is six PRs ahead (#502–#507); all three payment methods are still off.

Updated:
- PRD §14: date, payments re-checked, a "on `staging`, not yet released" paragraph (#502–#507, four additive migrations, nothing customer-facing yet).
- PRD §15: Accounting and Balance rows (MYR rate 1.0, prepaid/payable split, manual-supplier balance), Customer Login row points at PR 1b.
- PRD §16: item 113 line, the audit note.
- ADRs: ADR-121 addendum (ADR-124's three step-up routes; the set lives in `AdminMfaStepUpTest`), ADR-031 addendum (factory resolves by driver), ADR-046 and ADR-125 banners and index rows.
- `foundation-security.md`: step-up wording, a customer-login line under Secrets & Data.
- `backend/AGENTS.md`: the driver/capability convention, and "don't hang an invariant on a model event".

## 2026-10-18 — §16 item 113 PR 1b-i: login games, manual packages, login checkout, Manual Queue (ADR-125), backend

**Why.** PR 1a let manual suppliers exist. 1b makes Roblox and eFootball sellable: a login game, manual packages at an agreed cost, a checkout that stores the login, and a queue where an admin confirms each order. Split again into 1b-i (backend, this PR) and 1b-ii (admin + storefront screens) to keep the money review small.

**Stress test first.** Three read-only forks (catalog and checkout; fulfillment and ledger; readers and copy) re-traced decisions 4-9, 17, 23, 28, 31-35 against `staging` with 1a built; I re-checked the top findings by hand. Fourteen corrections, recorded as the ADR-125 2026-10-18 PR 1b pre-build addendum (decisions 36-47). The ones that would have lost money or leaked a login: decision 34's real-cost bound let a loss through (profit is `selling − real − affiliate_profit`); the member gate couldn't live in `CheckoutService` (the preview takes no game); `ContactMask::email()` returned a username unmasked; masking keyed on the game would fail once a game is deleted (`orders.game_id` has no FK); the password would have gone into the unsalted idempotency hash; an admin's wrong Failed had no way back except a second paid top-up. Founder answers: any differing real cost needs a reason; a supervised Failed→Delivered correction for manual suppliers; affiliates see the login masked; resend may correct the identifier.

**Shipped (1b-i).**
- Migration: `games.member_pricing_enabled` (default on), `price_change_logs.admin_user_id`.
- `Game::credentialFields()` / `isLoginGame()` / `identifierField()` / `memberPricingApplies()`; `GameFulfilmentRule` on login-game create, the schema endpoint, `UpdateGameRequest`, promote, category link (which now prohibits `credential_fields`) and combos. A login game needs manual delivery with its own subtext, no reseller code, no validator; switching kind is refused while any package exists.
- `POST /games` (login game), `PUT /games/{game}/credential-schema`, `POST /games/{game}/packages/manual`, `PATCH /packages/{package}/cost`, `GET /packages/{package}/price-history`.
- `PackageCostService` replaces `PackagePriceSyncService::propagatePrice` and the hand-written copy in pending-change approval; both record the admin.
- Checkout: the identifier field sets `player_id` (email lowercased) before validation and the blacklist; a client `player_id`/`server_id` is refused; consent required; `order_credentials` written in the order INSERT's transaction (`OrderFactory`); the hash carries only non-secret fields; `credentials.*` skips `TrimStrings` so a password is stored as typed. Member gate: the preview gets no membership id, `initiate` prices as a guest but keeps the member's email bound (decision 35, caught in self-review); catalog and the savings-badge sample honour it.
- `ManualSupplierAdapter::createOrder` returns Pending. `ManualDeliveryService` (Delivered / Failed / correct Failed→Delivered) calls `finalizePendingDelivery()`; real cost defaults to `cost_price`; each confirmation writes a `manual_confirm` attempt row with the admin. `GET /manual-queue`, `POST /orders/{order}/manual/{delivered,failed,correct-to-delivered}` (any admin). Mark Delivered refuses a manual order; Retry and Resend refuse a wiped login.
- `Order::isLoginOrder()` (has a credential row) and `publicPlayerLabel()`; Track Order / Reverb carry the masked identifier, `fulfilment_kind`, `player_label`, `login_wipe_hours`; the WhatsApp card masks it and asks for a password change on delivery; the affiliate order detail masks it.
- Resend's identifier correction updates `player_id` and `order_credentials.fields` under the lock; the admin request is pre-checked and audited (`credential_reveals.action = identifier`).

Tests: 7 new files (41 tests) plus `ManualCorrectionConcurrencyTest` (correction vs Issue Voucher, correction vs correction, real MySQL processes). Full suite 2889/2889.

**Gotchas.**
- `...GameFulfilmentRule::schemaRules()` spread after `'credential_fields' => [...]` silently replaced the list's own rule (a later string key wins); the list rule now stays with the caller.
- `OrderCredential::$fillable` has no `purged_at`, so a fixture that passes it creates an unwiped row; set it with a query.
- A background `php artisan` launched after the shell's cwd moved to the repo root fails with "Could not open input file: artisan" and still exits 0; run it from `backend/`.

**Next.** 1b-ii: admin and storefront screens, then the live click-through, then PR 2.

## 2026-10-18 — §16 item 113 PR 1b-ii: login top-up screens, admin and storefront (ADR-125)

**Why.** 1b-i (#509) built the backend; nothing could be clicked. This adds the screens and proves the whole flow end to end.

**Shipped.**
- Admin, `/admin/games`: "New Login Game" (name, subtext, member pricing, login form); a login game's detail shows "Login Form…" and "Add Package…" instead of "Create Combo…"; "Edit cost" on each manual package opens the cost form with its price history (who, when, sync or hand); Edit Game has the member-pricing switch and hides reseller code and validator for a login game.
- Admin, `/admin/manual-queue` (sidebar under Orders): paid Pending/NeedsReview manual orders, oldest first, with Stale and Login-wiped tags; Mark Delivered (real cost defaults to the agreed cost, a different one needs a reason) and Mark Failed. Order Detail shows the same actions plus "Correct to Delivered…" on a Failed, uncompensated manual order, labels the login, and hides Mark as Delivered, Confirm Failed and Check from Supplier for a manual supplier. Resend's correction becomes "Login Correction" with no Server ID.
- Backend extras: `GET /manual-suppliers` (an admin may add a manual package but the supplier list is super_admin middleware config); the game detail carries `credential_consent_text`, so the consent box shows the text the order snapshots; the admin's credential replace skips `TrimStrings` (closes the open edge from 1b-i).
- Storefront: Step 1 renders the game's login form (password with show/hide, choices, inline hints) and the consent box; the order sends `credentials` + `credential_consent`, never a `player_id`; the summary and review show only the identifier under its own label; the review's terms line, the fact line, the stepper ("Enter Login") and the status page (masked login, "usually within 30 minutes", delete-within-G-hours and change-your-password on delivery) read the fulfilment kind. A login game ignores a `player_id` in the URL.

**Verification.** Backend 2892/2892. Admin and storefront tsc, lint and build clean; admin unit tests 14/14. New e2e golden path `admin-login-topup.spec.ts` (ADR-023 Trigger A: a new resolution mechanism) drives the real UI: create the game and package, activate, check out with a login, pay (webhook), confirm in the Manual Queue, then the status page shows `p••••@example.com` and the password line; it also asserts the password never appears in review and the order's real cost is 4000 sen with a `manual_confirm` attempt. Full e2e suite 7/7. Screenshots of the login form, Manual Queue and status page checked by eye.

**Gotchas.**
- The e2e backend ran `CACHE_STORE=database`, which has no `Cache::tags()`. Every admin game write flushes the tagged catalog index, so the first spec that edited a game got a 500 (the game was still created), and the storefront's game list had been falling back on every read. Switched to `array` (holds nothing across requests there; it only loosens rate limits in that throwaway suite).

**Next.** Release when the founder asks; then PR 2 (supplier WhatsApp group bot).

## 2026-10-19 — §16 item 113 PR 2a: supplier WhatsApp group bot, backend (ADR-125)

**Why.** Through PR 1 a manual supplier's order stopped at the Manual Queue, and an admin closed it by hand. 2a connects the order to the supplier's own WhatsApp group: the supplier gets a post with a short login link and replies `.done` / `.gagal`.

**Design first.** A pre-build trace of decisions 11–16 against `staging`, plus read-only prod counts (2 reseller groups, 0 pending links, 14 customer notifications, 0 manual suppliers), found thirteen corrections. They are the ADR-125 2026-10-19 pre-build addendum (decisions 48–61) and the ADR-124 addendum. The founder chose a short link over a signed URL and an Admin → Partners → Suppliers page, super_admin only.

**Shipped (backend).**
- `whatsapp_groups` replaces `reseller_whatsapp_groups`, with the data copied as `owner_type = reseller`. `reseller_whatsapp_pending_links` is renamed to `whatsapp_pending_links`. `WhatsAppGroupService` holds the rules: a group never moves to another owner, a supplier has one active group, and an active manual supplier keeps its group.
- The webhook routes a supplier's group to `SupplierBotService` (`.done`, `.gagal <reason>`, `.pending`). These go through `ManualDeliveryService` at the agreed cost, with `confirmed_by = wa:<sender>` and `channel = supplier_bot`. The webhook also drops the bot's own messages (`fromMe`).
- The post is queued after Pending commits in `fulfill()`, one per (order, reference), on the bot session through the persisted queue. `WhatsAppOutbox` was extracted from `CustomerNotificationService` and gives each session its own send slot. The stored message holds a placeholder; the job mints the `/s/<token>` link at send time (hash-only `supplier_login_links`).
- Link page: GET shows the summary and username, POST shows the password, each reveal audited with actor supplier. The page sends `no-store`, `no-referrer` and `noindex`, and is throttled per IP and per link. It works only while that attempt is Pending.
- The reconciler sends one SLA reminder per attempt. Linking a group re-posts the supplier's waiting orders (decision 62). A manual supplier is created inactive and can be activated only with a group. `/api/partner-suppliers` and `/api/whatsapp-groups/pending` are built, for 2b's screens.

**Verification.**
- Backend fast suite 2919/2919. New files: `Adr125SupplierGroupPostTest`, `Adr125SupplierBotTest`, `Adr125SupplierGroupLinkTest`, and `Adr125WhatsAppGroupsMigrationTest` (migration down and up against seeded rows).
- Concurrency suite on MySQL: 33/37. The 4 failures are the known local `BackupRestoreMysqlTest` (no `mysqldump` locally); CI runs them.
- MySQL indexes checked on the new tables. Dev DB migrated with plain `migrate`.
- Real check against a local `artisan serve`: GET 200 with the three headers and no secret, the CSRF POST shows the password, and a bad token returns 404. Screenshots at 390 px (no horizontal scroll) checked by eye. The seeded rows were removed afterwards.

**Gotchas.**
- `SecurityHeaders` runs last and overwrote every `Referrer-Policy`, so the page's `no-referrer` would have been lost silently. It now sets the default only when none is set (decision 63).
- zsh does not word-split an unquoted `$files`, so a `for f in $files` rename loop ran once on the whole list. Use `xargs`.

**Next.** 2b: the Partners → Suppliers screen, the pending-link picker for both owners, Order Detail's "Supplier group" label, the Dashboard counts (failed supplier posts, active manual supplier without a group), and e2e.

## 2026-10-19 — §16 item 113 PR 2b: Partners → Suppliers screen, Dashboard counts, bot golden path (ADR-125)

**Why.** 2a (#512, merged by the founder) built the supplier bot with no screen to link a supplier's group. 2b adds the screens and proves the bot end to end.

**Found while building.** A supplier's `is_active` is read by no catalog or checkout path, only by the sync and balance jobs. So 2a's "activate needs a group" rule guarded a flag no customer reads: an inactive manual supplier's packages were still for sale. Decision 64 fixes it through ADR-046's existing `supplier_issue` bulk switch, moved out of `SupplierController` into `SupplierPackageStatusService`. `ManualSupplierStatusService` is the one switch for both screens; it changes the flag and the packages in one transaction. Behaviour for API suppliers is unchanged, and the 23 supplier-controller tests pass as before.

**Shipped.**
- `/admin/suppliers` under Partners (super_admin): group, SLA, waiting count, status; link and unlink through `WhatsAppGroupLinker`, now shared with the reseller modal; Edit SLA; Activate (disabled with no group); Deactivate with a packages-off-sale warning.
- Group types and the pending list moved to `lib/whatsapp-groups.ts`.
- Order Detail labels a bot row "Supplier group" with its group id.
- Dashboard: red `supplier_posts_not_delivered` (current attempt's post failed or skipped) and amber `manual_supplier_without_group`.
- Manual Queue copy now says it is the fallback.
- e2e `supplier-group-bot.spec.ts`, with a new `E2ESeeder` supplier and game, plus a bot session and webhook secret in `boot-backend.sh` (nothing is sent).

**Verification.**
- Backend 2921/2921.
- Admin tsc, lint and unit tests 15/15.
- The local e2e run was not possible: the founder's `dev.sh` holds :3000/:3001 and the harness doesn't reuse servers, so the new spec runs on CI. The seeder was checked by running it against a throwaway sqlite file.
- Real browser check against the running dev stack with seeded local rows (removed afterwards). Activate was disabled before linking; the link from the pending list worked; activate and SLA 45 saved; unlinking an active supplier's group was refused with the backend's message; the Dashboard showed "1 order not posted to the supplier"; Order Detail showed the Supplier group cards. Linking also re-posted the waiting order (decision 62), skipped locally with "Bot WhatsApp session is not configured". At 390 px the page doesn't scroll sideways (the table scrolls inside its card).

**Next.** Item 113 is fully built on `staging`. Release (`staging`→`main`) is the founder's call. Before a manual supplier goes live in prod: create it, link its group, activate it, then add its packages.

## 2026-10-19 — Release #514: item 112 (customer login base) + item 113 (Login Top-up, supplier WhatsApp bot)

`staging` → `main` merged as `8982994c`, CI deploy job green; prod not yet verified.

- **Ships:** Item 112 (#506, customer account credentials base, ADR-124), Item 113 PR 1a (#507, supplier driver + manual suppliers), PR 1b (#509 backend, #510 screens: login games, manual packages, login checkout, Manual Queue, masked login), PR 2a (#512, supplier WhatsApp group bot backend, short login link), PR 2b (#513, Partners → Suppliers screen, package-off-sale switch on deactivation), and docs #502–#505, #508, #511.
- **Migrations (8):** `order_credentials`, `credential_reveals`, Customer Login Policy columns on `platform_settings`, `suppliers.driver` + `funding_mode`, `games.member_pricing_enabled` + `price_change_logs.admin_user_id`, `whatsapp_groups`, `customer_notifications.session` + `chat_id`, and `supplier_login_links`.
- **Prod state before merge (2026-10-19, read-only check in PR #514):** 0 orders processing/pending/needs_review; 2 reseller groups (both active); 0 pending links; suppliers gamevion + digiflazz; all 3 payment methods off.
- **Verification on the box:** pending founder confirmation.

## 2026-10-19 — Admin reads skip the CORS preflight; preflight answers cached (ADR-121 addendum, on `staging`)

**Why.** The founder saw `/admin/orders` sometimes show "Loading…" and sometimes load at once. A read-only trace found no N+1 and an efficient Orders query (eager loads, `withExists`, one batched wallet-refund lookup). The cost was in the transport: every admin GET sent `Content-Type: application/json` with no body, which is not CORS-safelisted, and `config/cors.php` had Laravel's default `max_age => 0`. So each read was OPTIONS + GET, each a full Laravel boot through Cloudflare: opening Orders was six round trips, not three, and the 60-second summary poll two. Before ADR-121 the Bearer header forced the preflight anyway; the leftover `Content-Type` kept it after the cookie session removed that header. Gemini's independent review of the same findings agreed, and added that the storefront's `X-Storefront-Host` keeps its preflight by design.

**Shipped.**
- `admin/src/lib/api-client.ts`: `apiFetch` sends `Content-Type` only with a body. To let `node --test` load the module, the session import is a relative `.ts` path and `ApiError` uses plain fields instead of constructor parameter properties (same behaviour).
- `admin/src/lib/api-client.test.ts`: a GET carries only CORS-safelisted headers; a POST still sends its JSON `Content-Type` and the CSRF header.
- `backend/config/cors.php`: `max_age` 7200 (Chrome's cap), for every origin; its header comment no longer calls the admin a Bearer-token app.
- `CorsConfigTest`: pins `Access-Control-Max-Age: 7200`.
- ADR-121 2026-10-19 addendum (decision, safety note for ADR-078 custom domains, what is unmeasured).

**Verification.**
- Admin: unit tests 17/17; the new GET test failed on the old line and passes on the new; `tsc`, `eslint` and `next build` clean.
- Backend: not run in this session. The container has PHP 8.3 and Symfony 8 needs 8.4 (property hooks), and the Debian package mirror is blocked for a Docker image. The new test's red is shown from code instead: `HandleCors` calls `CorsService::setOptions(config('cors'))` per request and `CorsService` writes `(string) $maxAge` to the header, so `0` gives `"0"`, not `"7200"`. CI runs the full suite on PHP 8.4.
- Not measured yet: the gain on real requests. After release, open `/admin/orders` with DevTools → Network: the GETs to `/api/orders`, `/api/orders/summary` and `/api/games` should have no OPTIONS before them.

**Not changed, on purpose.** The storefront and portal clients (their preflight comes from a header they need), the hard-refresh path (JS download, hydration), and any server contention around the :00/:15/:30/:45 scheduler ticks (unmeasured; Pulse first). A shared admin loading skeleton (the admin counterpart of the storefront's ADR-071 primitive) is a separate decision for the founder.

## 2026-10-19 — Release #517 (`staging`→`main`): #516 admin preflight fix + #515 docs sync; session wrap-up

**Release.** PR #517 merged as `6a5052e`. CI run #635 green on `main`, including `backend-tests`, `backend-concurrency` and `playwright`; the `deploy` job called the Forge hook at 16:59 UTC. No migration, no env change. **Prod not yet verified**, for this release or for #514: the founder will run the read-only checks next session (PRD §16 item 124).

**What this session did, in order.**
1. **Status audit.** Release #514 had gone live with every status doc still saying "on `staging`". A Claude audit and an independent Gemini audit were cross-checked: each found real items the other missed (Gemini: `foundation-security.md`, README, AUTH-7, two §16 lines; Claude: the Glossary's Manual Queue cost rule against ADR-125 decision 36, the §10 OpenWA row, the §8 Package row). Gemini wrote the fix as #515 and Claude reviewed its diff. The review caught two errors before merge: a decision cited by the wrong number, and an edit to an old build-log entry (this file is append-only). Claude made one error, too: it truncated a command's output at 400 characters and reported ADR-125's banner as missing its 2b note, which Gemini correctly rejected.
2. **Admin "Loading…" investigation**, then #516 (entry above, ADR-121 2026-10-19 addendum).
3. **Release #517.**

**Gotchas.**
- **GitHub's per-job status lags.** On #516, `list_workflow_jobs` and the PR check list showed jobs "queued" or "in progress" for 20+ minutes after their steps had finished. Read the workflow run's own `status`/`conclusion`, or a job's step list, before calling CI stuck.
- **This cloud container cannot run the backend suite.** It has PHP 8.3; Symfony 8 needs 8.4 (a parse error in `vendor/symfony/http-foundation/Request.php`). The Debian mirror is blocked by the session's network policy, so a Docker PHP 8.4 image with `zip`/`intl` could not be built either. CI on PHP 8.4 is the gate; `backend/AGENTS.md` gotcha 9 records it.
- **Docs drift has a structural cause.** Status lived in 6+ places, so each release left some of them stale. That is now PRD §16 item 127.

**Open items added to PRD §16:** 124 (verify #514 and #517 on prod, measure #516), 125 (branch protection: `AGENTS.md` vs `ci.yml`), 126 (PRD spec for Login Top-up), 127 (status in one place), 128 (shared admin loading skeleton, after measurement). Item 114 (Joki) now notes that ADR-126 must be re-traced against ADR-125 decisions 48–64 before it is built.

**Next session starts with item 124.**

## 2026-10-22 — Prod box patched for PHP RCE CVE-2026-109618/109619 + Node security release

**Why.** DigitalOcean and Forge emailed about two PHP RCE CVEs disclosed 2026-10-21. They affect every PHP version, and public PoC code exists. The patched release for our line is 8.4.27. Forge also flagged a separate Node security release the same day.

**Before (read-only check, `pekangame-prod-lwf`).** PHP CLI + FPM 8.4.25. Ubuntu 24.04.5, so Forge "Update PHP" was supported. Node 22.23.3 (nodesource), which runs OpenWA. The apt lists dated 2026-10-12, before the disclosure, so they still showed 8.4.26 as the newest version.

**What ran (by the founder; `sudo` needs a password on this box).**
- Forge → "Update PHP". dpkg shows `php8.4-*` 8.4.25 → 8.4.27 at 01:34 UTC. php-fpm, Horizon, Reverb, Pulse and OpenWA all restarted at 01:35.
- DO droplet console as root: `apt-get install --only-upgrade nodejs`. dpkg shows 22.23.3 → 22.23.4 at 01:48 UTC. OpenWA was restarted from Forge → Background processes.

**After (verified over SSH).**
- `php -v` and `php-fpm8.4 -v`: 8.4.27, built 2026-10-21.
- Every long-running PHP process (Horizon master, 9 supervisors, 10 workers, Reverb, Pulse) started after the upgrade. None maps a deleted binary. The only `(deleted)` map is `/dev/zero`, which is anonymous shared memory.
- `node -v`: 22.23.4. `node dist/main` started at 01:51 on `/usr/bin/node`. Both OpenWA sessions (`reseller-bot`, `customer-support`) were `ready` by 01:50:56.
- `api.pekangame.space/up` returned 200 and `bot.pekangame.space` returned 200. `bot.` returned 502 for about 90 s while OpenWA rebuilt on start, which is expected.

**Not affected / nothing to do.**
- DO Managed MySQL (8.4.8): it runs no PHP, and DO patches it.
- CI: `setup-php` uses `"8.4"`, so it picks up the latest patch.
- Frontends: they run on Vercel.
- The founder's local Herd is still on 8.4.23. It is not internet-facing; update it when convenient.

**Gotcha.** Long-running PHP daemons keep the old binary in memory until they restart. "Update PHP" restarting FPM alone is not enough, so check the daemon start times against the dpkg timestamp.

## 2026-10-22 — Review of #516 and #517, orphaned docs brought in, branch ruleset (ADR-037 addendum)

**#516 review.** The founder asked for a check of the work done without Claude, and of whether the fix was the right one. The fix treats the root cause. Every admin read goes through `apiRequest`. No tracing library adds headers. `Accept: application/json` stays, so a 401 is still JSON. Verified on prod: an admin-origin OPTIONS returns `access-control-max-age: 7200`, and a GET without `Content-Type` still gets `allow-origin` and `allow-credentials`. A same-origin `/api` proxy would remove CORS entirely, but it adds a hop and runs against ADR-121's session design, so it is not worth it. One wording note: `cors.php` says a deactivated custom domain is "blocked on its next request". In fact, for up to 2 h a cached preflight still lets it *send* a non-simple request; only reading the response is blocked. That is harmless, because CORS is not auth: Host resolution and CSRF still apply.

**Orphaned docs.** Three commits had been pushed to `claude/gallant-bardeen-7hedes` after #516 merged, so they never reached `staging`: the PRD #517 status and items 124–128, the #517 build-log entry above, and `backend/AGENTS.md` gotcha 9. They are cherry-picked into this PR. **Lesson:** a commit pushed to a branch after its PR merges goes nowhere. Open a new PR for it.

**Item 124, box half (read-only).**
- `current` is `6a5052e`.
- #514's eight migrations show Ran.
- Horizon is running.
- `whatsapp_groups` has 2 reseller rows and 1 supplier row, all active.
- The bot session (`OPENWA_SESSION_ID` + `OPENWA_API_KEY`) is set.
- The DevTools and Pulse measurement remains, and needs the founder's login.

**`/api/health` 503 (`scheduler: false`), not ours.** `api.gamevion.com` refused connections from the box and from the founder's Mac from 2026-10-21 16:30 UTC onward. `supplier-balance-refresh` failed at 00:00 UTC and the Gamevion price sync failed every 30 minutes. No orders were placed in that window (all payment methods are off). Health recovers on its own once the task succeeds again.

**Item 125.** Neither `staging` nor `main` was protected (`protected: false`, no rulesets). The repo is public on purpose, for unlimited Actions minutes. `ci.yml` drops the docs-only `paths-ignore`, which with required checks would have wedged every docs PR. The ruleset (id 25608722) was created the same day, while #520 was open; #520 changed `ci.yml`, so its own required checks ran (ADR-037 2026-10-22 addendum). #518, which carried the three orphaned commits, was closed as superseded by #520.

## 2026-10-22 — Item 124 closed: #516 measured on prod

- **DevTools (the founder, logged in):** `/admin/orders` reloaded with the filter set to All and `method:OPTIONS`, and **0 OPTIONS requests**. Every admin GET (`orders`, `games`, `summary`, `manual-queue`, …) goes straight to the API. Note for next time: Chrome lists a preflight as Type `preflight`, which the Fetch/XHR filter hides, so check under All.
- **Pulse (read-only, last 3 days):** 15 slow requests, spread across every minute of the quarter-hour. Only 2 landed on :00/:15/:30/:45, so there is no sign of scheduler contention. No admin endpoint is in the slowest list. The top entries are public: `catalog/reseller-price-list` (max 5.1 s), `catalog/branding`, `validate-player`. Load average was 0.1.
- **Pulse has no CPU or memory data:** the `pulse:check` daemon (Servers card) isn't running on the box. Add it only if server-level metrics are wanted.
- Item 128 (admin skeleton) stays in group D, at the founder's call.

## 2026-10-22 — Docs: items 126 and 127 closed, item 96 retargeted with the OpenWA backup gap

**Item 96.**
- Retargeted from OpenWA v0.24.0 to **v0.25.0** (released 2026-10-15).
- Both changelogs were checked against our two calls (`send-text` and inbound webhooks). One change touches us: on Baileys, a send to a number not on WhatsApp now answers `400` (it used to answer `201` and drop the message). `OpenWaClient::sendNow()` would retry that three times, so the upgrade PR makes `400` non-retryable.
- **New gap folded in.** OpenWA's `data/main.sqlite` and `openwa.sqlite` (39 MB, up from 22 MB on 10-14) live only on the box and have **no backup**. The daily `database-backup` covers Laravel's MySQL only, and the box has no cron and no backup folder. ADR-075 had pointed this gap at item 56, which never built it; its text now points at item 96.

**Item 127.** Build/live status now lives in §15 only (headline in §14). It was removed from:
- the Glossary;
- §6: AUTH-7's priority cell, the §6.7 status column (now "Decided in", naming the ADR), RPT-4, THM-1 and MUI;
- §7.3's prod flag and §8's BlacklistEntry row;
- the ADR-046 and ADR-125 Standing banners.

`adr.md`'s rule now says that the index, every Standing banner and the Glossary never say "built", "live" or "on `staging`".

**Item 126.** The Login Top-up spec is written, from ADR-124/125 and checked against the code:
- §6.24: LOGIN-1..7 and MSUP-1..7.
- §7.6: the order flow.
- §8: Game and Supplier columns, plus OrderCredential, CredentialReveal, WhatsAppGroup, SupplierLoginLink and CustomerNotification.
- §11: now points at §16 group E, and its shipped strikethrough lines are gone.

Verified in code on the way: the policy defaults (G 24 h, H 24 h, D 14 days, 20 reveals an hour), the SLA bounds of 5–1440 minutes, the `/s/{token}` GET/POST routes, the combo rule through `GameFulfilmentRule::comboComponentSupplier`, and affiliate masking through `publicPlayerLabel()`. Correction: `reply_sla_minutes` is a key in `api_config.manual`, not a Supplier column, as item 126's text had said.

**Not done (left on purpose).** §10's integration status cells (Gamevion "unfunded", CHIP live keys) still carry status. They describe the integrations themselves, so they stay until a release changes them.

## 2026-10-22 — OpenWA upgraded v0.23.7 → v0.25.0 (item 96, upgrade part)

**Why.** v0.24.0 and v0.25.0 bring security and Baileys fixes. Prod runs OpenWA from a git checkout, so nothing updates it by itself.

**What ran (founder's go-ahead per phase; `forge` over SSH, daemon stop/start/edit by the founder in Forge).**
- **Phase A, no downtime.** `git clone ~/OpenWA ~/OpenWA-v0.25.0`, fetched tags from `rmyndharis/OpenWA`, checked out `v0.25.0`, then `npm ci`, `npm --prefix dashboard ci` and `npm run build:all` under `nice`. The build passed and `package.json` reads `0.25.0`. The old OpenWA kept answering `/api/health` 200 throughout.
- **Phase B.** The founder stopped the daemon (05:23 UTC). Backup: `~/openwa-backups/openwa-v0.23.7-20261022-0524.tar.gz` (27 MB, `data/` + `.env`). `data/` (49 MB) and `.env` were copied into the new folder, then `~/OpenWA` → `~/OpenWA-v0.23.7` and `~/OpenWA-v0.25.0` → `~/OpenWA`. The founder changed the Supervisor `command=npm run prod` to `command=/usr/bin/node dist/main` (directory unchanged) and started it at 05:25:57.

**After (verified).**
- `/usr/bin/node dist/main` is running and `/api/health` returns `ok`. Nest started in about 1 s, where a restart used to rebuild for about 90 s.
- Both sessions auto-started: `reseller-bot` was ready at 05:26:00 and `customer-support` at 05:26:03. Laravel's `/api/health/whatsapp` reports both `ready`.
- No `error` or `fatal` log lines. There are two harmless warnings: Docker is not available, and `WEBHOOK_SHUTDOWN_DRAIN_MS` (5 s) is shorter than `WEBHOOK_TIMEOUT` (10 s).
- `.baki` in the test group arrived at 05:27:35, and the reply "Baki wallet anda: RM3.44" went out at 05:27:38. A real customer's receipt request on the CS number (05:28:14) was answered at 05:28:20 with status `delivered`. Every `webhook_outbox_events` row is `dispatched`, including the `session.status` events queued while the daemon was stopped.
- Downtime was about 3 minutes (05:23–05:26).

**Gotchas.**
- `messages` shows each group message twice, once per session, because the CS number is also a member of the test group. It looked the same on 2026-10-16, before the upgrade, and Laravel answers once.
- The `400`-on-unknown-number change is now live while `OpenWaClient` still retries it three times. That is noisy, not harmful, and the fix is on item 96.

**Rollback (if needed).** Stop the daemon, swap the folder names back, and start it. The old `data/` in `~/OpenWA-v0.23.7` is untouched; only messages received since 05:26 would be lost from OpenWA's own history.

**Left on item 96.** The off-box backup of `data/`, the `400` fix, `MESSAGE_RETENTION_DAYS`, and deleting `~/OpenWA-v0.23.7` plus the `.env.bak-*` files after a few clean days.

## 2026-10-22 — Item 96: a number not on WhatsApp fails at once (on `staging`)

**Why.** OpenWA v0.25.0 (live since this morning) answers `400` to a send to a number that is not on WhatsApp, where v0.23.7 answered `201` and dropped the message. `OpenWaClient::sendNow()` threw on any failure, so each such send was retried three times (customer notifications: backoff 60 s / 300 s).

**Gotcha found before building.** The plan said "make `400` non-retryable". OpenWA's own source (`engine-status-responses.ts`, `engine-registry.service.ts`) shows `400` is also the answer while a session is **not started yet**, which happens for a few seconds after every OpenWA restart. A blanket rule would have dropped every message sent in that window. A session that is reconnecting answers `409`; that keeps retrying, as before.

**What shipped.**
- `OpenWaClient::sendNow()` throws the new `OpenWaRecipientNotOnWhatsAppException` only on a `400` whose message says "is not registered" (Baileys) or "could not resolve the recipient" (whatsapp-web.js). Its message reads "The number is not on WhatsApp (OpenWA 400: …)", which the admin sees on the failed `customer_notifications` row.
- `SendCustomerWhatsAppJob` and `SendResellerBotReplyJob` fail at once on it, through Laravel's `FailOnException` job middleware. Pacing (`429`), `400` "Session is not started", `409`, `5xx` and connection errors all still retry.
- If OpenWA rewords the message, the match fails and the send falls back to retrying, which is the safe side. Recheck the two phrases on each OpenWA upgrade.

**Verified.** 5 new tests (red → green): the client for both kinds of `400`, and both jobs through their middleware. Full suite: 2927 passed.

## 2026-10-22 — Item 96: OpenWA backup to R2, and backup encryption enforced (ADR-039 addendum, on `staging`)

**Why.** OpenWA's SQLite data and WhatsApp session keys lived only on the droplet. A grill with the founder (Q1–Q9, all recommendations accepted) settled the design: a second source of the one ADR-039 backup system, not a side script.

**Found while grilling.** ADR-039 decision 4 (archives encrypted) was never true in prod. `BACKUP_ARCHIVE_PASSWORD` is absent from prod's `.env`, and `config('backup.backup.password')` is empty (checked read-only, as a boolean). spatie writes a plain zip in that case, so every database archive on R2 since go-live is plaintext. PRD §15 had said "encrypted"; it is corrected. The fix is fail-closed: in production, a run without the password fails and alerts instead.

**What shipped (one PR).**
- `backup_runs.source` (`database` / `openwa`). `BackupRun::queue()` is the one start path for the schedule and "Backup Now". `app:run-backup --source=`.
- `RunOpenWaBackupJob` and `OpenWaBackup`:
  - OpenWA's `scripts/backup.sh` makes the archive, plus OpenWA's `.env`, in one AES-256 zip, uploaded to `r2_backups/openwa/`;
  - restore test through OpenWA's `scripts/restore.sh` into a throwaway dir, then integrity, API keys, configured sessions, Baileys JSON and `creds.json` checks;
  - prune past 14 days, only after a passing run.
- `BackupArchivePassword::require()` guards both sources. `BackupRestorer::extractOpenWa()` is shared by the nightly test and the new `app:restore-openwa-backup`. `app:restore-backup` refuses an OpenWA run.
- Schedule `openwa-backup`, 19:37 UTC, `CRITICAL`. Stats and the Dashboard judge each source's latest run.
- Admin `/middleware/backups`: Backup Database / Backup OpenWA buttons, a last-run card per source, a source filter and column. Checked in a real browser at 1440 px and 390 px (no page overflow; the header stacks on mobile).

**Gotchas.**
- The plan's "make it simple" version would have run OpenWA's scripts with Laravel's environment. They read `DATABASE_NAME` and friends from the environment before `./.env`, and Laravel's dotenv exports every variable. So they run under `env -i`.
- The first leak test passed even without `-i`. Symfony Process inherits from `$_SERVER`/`$_ENV`, not `putenv`, and the test now sets all three, as dotenv does. It fails without `-i` (mutation-checked).
- The script contract was proven against the real OpenWA v0.25.0 scripts, run locally on fake data: `backup.sh` leaves one flat `openwa-backup-*.tar.gz`, and `restore.sh` with `OPENWA_DATA_DIR=<tmp>` writes only there. Nothing ran against prod for this.
- `OpenWaBackup` isn't `final` so the job test can mock it, the same as `BackupRestoreTester`.

**Verified.** 22 new tests, including a real-process end-to-end test of encryption, the restore test, a torn Baileys file, a missing session, a wrong password, a failing script, the env leak and pruning. Full backend suite: 2953 passed. Admin `tsc`, `eslint` and `next build` are clean. `BackupRestoreMysqlTest` can't run on this Mac (no `mysqldump`), so CI's `backend-concurrency` covers it.

**Not done yet (needs the founder, in order).** Set `BACKUP_ARCHIVE_PASSWORD` (kept in the password manager first) and `OPENWA_INSTALL_DIR` on Forge, then release, then "Backup Now → OpenWA" and a restore drill with the password from the password manager. After that, delete the plaintext R2 archives and the on-box leftovers. These are PRD §16 item 96's steps.

## 2026-10-22 — Item 96 rollout step 1: backup password set on prod

The founder generated a new password, kept it in the password manager, and set `BACKUP_ARCHIVE_PASSWORD` and `OPENWA_INSTALL_DIR=/home/forge/OpenWA` in Forge → Environment. Checked read-only, values never printed: both keys appear once in the site-root `.env`, the password is filled (64 chars), and `/home/forge/OpenWA/scripts/backup.sh` exists.

**Gotcha: Forge re-caches config on an env save.** `.env` was written at 09:07:01 and `bootstrap/cache/config.php` rebuilt at 09:07:05 with no deploy (current release still #517), and `config('backup.backup.password')` read filled. So no `config:cache` was needed. Horizon was still holding the old config in memory (up since 07:01), so with the founder's go-ahead `php artisan horizon:terminate` ran; Supervisor brought Horizon back at 09:09:39 and `horizon:status` reads running.

Released code (#517) does not read `OPENWA_INSTALL_DIR` yet; that waits for the `staging`→`main` release. The 02:07 UTC database run tonight is the first encrypted archive; its `backup_runs` row is the evidence to check.

## 2026-10-22 — Release #526 (`staging`→`main`): OpenWA backup, backup encryption enforced, OpenWA `400`

Requested by the founder after the docs pass (#525). Merged as `d2f841b`; every CI job and `deploy` green. Box checked read-only: `current` is `d2f841b`, migration `2026_10_22_100000_add_source_to_backup_runs` ran (batch 47), Horizon running, `config('backup.openwa.install_dir')` = `/home/forge/OpenWA`, the backup password reads filled, and the schedule has `app:run-backup --source=database` at 02:07 and `--source=openwa` at 19:37 UTC.

**`/api/health` was already 503, unrelated to the release.** `supplier-balance-refresh` (CRITICAL) failed at 00:00 UTC because `api.gamevion.com` refused connections (`cURL error 7`); Gamevion's hourly price sync failed until at least 08:00 UTC. Gamevion is unfunded, so no order was affected, and it answered again by 09:40 UTC. The daily task stays stale until its next run succeeds. Worth a look later: one unfunded supplier being down turns the whole health check red. Digiflazz's balance is also under its alert threshold (IDR 1.56M vs 10M, 2026-10-21).

## 2026-10-22 — Refund Policy page and the Bank Negara Malaysia lines removed (CHIP merchant review, on `staging`)

CHIP is reviewing LWF Group Sdn. Bhd.'s merchant account. Its list included a refund policy page that allows a refund to the original payment method, and contact details on About Us. It also asked about a BNM licence, which the storefront appeared to claim: "Licensed & compliant with Bank Negara Malaysia standards" sat under the CHIP badge in the footer and the home payment section, and "Bank Negara Malaysia compliant" in the membership and checkout review modals. All four lines are removed; the CHIP badge stays.

The new page is a fourth admin-edited legal page (`affiliate_footer_settings.refund_policy_content`, sanitized like the other three, served by `/api/catalog/legal/refund-policy`, rendered at `/refund-policy`). It is linked from the footer, the mobile drawer, the sitemap (6 static routes now) and `llms.txt`. Checked in a browser on the e2e harness: the admin saves the field, the storefront page shows it with `{store_name}` resolved, the footer link is there, and no "Bank Negara" text remains on the home or refund page.

The founder decided the policy itself: after retries, a voucher for the full amount or a full refund to the original payment method within 7 working days, at the customer's choice. That revises ADR-004. How a refund is made and recorded is not designed yet (PRD §16 item 129), and it must be built before payment methods are switched on for the new account. The page text is admin content, so it can be published before then.

## 2026-10-22 — Release #528 (`staging`→`main`): Refund Policy page live

Requested by the founder. Merged as `d26c795`; CI and `deploy` green. Box checked: migration `2026_10_22_120000_add_refund_policy_content_to_affiliate_footer_settings` ran (batch 48), Horizon running, `/api/catalog/legal/refund-policy` 200, `https://pekangame.com/refund-policy` 200, no "Bank Negara" text on the home page. The founder then filled Refund Policy, About Us (contact block with the business address) and Terms (sections 1 and 5) in admin; checked through the public API: the refund page and terms mention 7 working days, about-us has the address, the old "do not process cash payouts" line is gone. The reply to CHIP is sent by the founder. `/api/health` still 503 from the Gamevion outage (previous entry); new PRD §16 item 130.

## 2026-10-23 — First real Login Top-up order on prod; FPX on for CHIP's review; item 129 on hold

**Status check (read-only).** The first encrypted database backup (run #57, 02:07 UTC) and the first scheduled OpenWA backup (#56, 19:37 UTC 2026-10-22) both passed their restore tests. `/api/health` is back to 200. Digiflazz is at IDR 797,643, which the founder accepts because no buyers are expected right now.

**FPX is on, on purpose.** `fpx_chip` has been active since 2026-10-22 09:52 UTC so CHIP can review the site. The founder will switch it off again. PRD §14 had said all three methods were off; corrected.

**Item 129 on hold.** The founder says the refund-to-original-method wording exists for CHIP's merchant review, and the policy goes back to voucher-only once CHIP approves. Moved to PRD §16 group D with that trigger.

**Login Top-up, end to end on prod (ADR-124/125, first real order).** The founder bought PG-YTVHCNMQ2SZX (Roblox, test package "0 Robux", Gamevion Manual, `pay_after`), paid RM1.57 through FPX. Checked read-only at each step:
- Paid at 04:36:42 UTC, order `pending`, `initial` attempt `pending`; `order_credentials` held the username in plain and the five secret fields encrypted, with the consent text.
- The `supplier_order_post` was sent to the supplier group at 04:36:51. The founder opened the full-login link at 04:37:22, which wrote five `credential_reveals` rows (actor supplier, with IP).
- `.done` in the group at 04:42:00: order `delivered`, real cost 50 sen (the agreed cost), profit 7 sen, the `initial` attempt resolved `success` plus a `manual_confirm` row naming `wa:601118884011` (decision 42; no reader sums attempt costs). Gamevion Manual got one `ORDER_DRAWDOWN` of −RM0.50, the amount owed. The bot replied in the group (the founder confirmed; the reply is a direct send, not queued).
- The link stops working once the order leaves `pending`. `app:wipe-order-credentials` stamped `closed_at` at 04:45:07; the secrets are wiped G = 24 h later.
- Track Order shows Paid / Delivered with the "change your game password" line (founder's screenshot).

## 2026-10-23 — Item 130: a balance check never throws (ADR-069 addendum)

**Root cause, from the prod log of 2026-10-22 00:00:26 UTC.** Gamevion refused connections; `GamevionAdapter::checkBalance()` let the `ConnectionException` through, and `CircuitBreakingSupplierAdapter` rethrows it on purpose (K-3: `createOrder` needs the throw for M-1). `app:refresh-supplier-balances` didn't catch it, so the sweep exited 1 at Gamevion. Digiflazz, next in line, wasn't refreshed that day, and the CRITICAL heartbeat turned `/api/health` red. The same throw 500'd Refresh Balance, the credential probe and Developer Tools. The item's first framing ("health judges an unfunded supplier") would have patched only the symptom.

**Fix, at the seam.** The breaker decorator returns a connection failure as `CONNECTION_FAILED` for `checkBalance` only, after counting it once. Order calls and `listProducts` are unchanged. The daily refresh now isolates each supplier: an unreachable one is recorded and the task succeeds; an unexpected exception is recorded `failed: [ERROR] …`, reported, the rest still refresh, and the task exits FAILURE.

**Tests (red first).** The breaker test that used `checkBalance` to prove propagation now uses `createOrder`/`checkStatus`. New: a balance connection failure is returned and counted once (threshold 2); the daily refresh keeps refreshing Digiflazz after an unreachable Gamevion (real decorator) and after a crashing one (task fails); Refresh Balance on an unreachable supplier (real Gamevion binding, `Http::failedConnection`) returns 200 with `CONNECTION_FAILED` and keeps the last balance (it was a 500). Full suite: 2958 passed. No migration, no frontend change.

## 2026-10-23 — Joki (item 114): ADR-126 re-traced and re-grilled before building

**Why.** ADR-126 dated from 2026-10-17, before ADR-125's decisions 48–64 were built. The founder asked for a full re-check before building ("tidak tampal menampal").

**How.**
- Three read-only code-trace forks, one per layer: checkout/pricing, ledger/fulfilment, downstream readers. Their top findings were re-checked by hand.
- A grill with the founder in Malay (S1–S26), then a final stress test (F1–F5).
- Competitor price lists (Gamevion, Muzzzyyy) compared against a draft rate table.

**Findings that changed the design:**
- **Post dedupe key.** It had no supplier, so an assigned penjoki's group would never get the post.
- **Reassign vs `.done` race.** The old penjoki's `.done` could book the drawdown against the new penjoki.
- **Resend.** It rewrites `cost_price` and `supplier_id` from the package, even on a plain retry.
- **Bulk Markup.** It rewrites every non-combo package's price.
- **Stale rule.** It reads `orders.updated_at`, and the manual SLA maximum is 1 day.
- **Wipe ceiling.** D = 14 days for every login.
- **`PricingBasis::label()`.** It would throw on a new case, crashing the Reports export.
- **Revenue month.** Revenue is scoped by `paid_at` but the customer liability by `delivered_at`, which drifts closed months for a multi-day joki. That is now item 131, a launch gate.

**Result.** The ADR-126 2026-10-23 addendum (25 decisions), with pointer addenda in ADR-124 and ADR-125. Item 114 moves to group A, with five PRs and launch gates. The rapspoint page refused automated fetches (HTTP 403), so the draft rates use the 2026-10-17 rates recorded in ADR-126 at the platform's own IDR rate (0.000226, 2026-10-23). Competitor prices confirmed the 25/50-star Mythic thresholds.

## 2026-10-23 — Joki PR 1 (item 114): shared manual-order seams (ADR-126 decisions 17, 18, 20, 22), PR #533 to `staging`

Nothing joki-specific yet. Every manual order, which today means Login Top-up, gets these fixes.

- **Post key (17).** The dedupe key is `…:ref:<ref>:supplier:<id>`. RETRY compares the reference only, so a reassigned order's first post to a new group reads ORDER BARU. A migration appends each existing row's current supplier. Nothing reassigns an order yet, so that supplier is the one the post went to.
- **Supplier re-check (18).** `finalizePendingDelivery()` takes an optional `expectedSupplierId` and refuses inside its own lock, writing nothing. A first version wrapped finalize in an outer transaction instead. It was dropped because a contradicting result's `late_supplier_result` evidence, which finalize commits before throwing, would have been rolled back. The admin sends the supplier on screen (required by both FormRequests), and the bot sends the group's supplier.
- **Stale clock (20).** `orders.manual_activity_at` is stamped in `SupplierGroupNotifier::prepare()` when the post is actually sent, so a post skipped for having no group starts no clock. `Order::pendingClock()` returns it, else `updated_at`. The SLA reminder's minutes and `.pending` read it too. SLA max is 4320 minutes.
- **Login ceiling (22).** `order_credentials.secrets_expire_at` (NOT NULL) comes from `OrderCredential::expiryFor()`. `games.credential_max_days` is set in Edit Game, for a login game only. Before, the sweep read D live, so lowering D applied to every held login at once. To keep that behaviour, changing D or a game's days recomputes the unwiped logins it governs (`refreshExpiry()`).
- **Gotchas.** The migration loops use `get()`, not `each()`: `each()` pages by offset while the loop rewrites the column it filters on, which skips rows. Testing the migrations on docker MySQL left the `llm_report_*` views behind, and every concurrency test then errored (`backend/AGENTS.md`, gotcha 8).
- **Verified.** `php artisan test`: 2969 passed, including 11 new tests, red first. Concurrency suite: 33/37; the 4 are `BackupRestoreMysqlTest` (no local `mysqldump`). Migrations on MySQL: rewrite, backfill (set_at + D), NOT NULL and `down()`. Admin: tsc, lint, build, 17 unit tests. e2e: 8/8 on the last full run. Two earlier full runs each had one spec fail once (`admin-login-topup`, `storefront-checkout`), and both passed alone and on the rerun. `admin-login-topup` now sets a login game's days to 30 and asserts a 30-day ceiling on the order.

## 2026-10-23 — Joki PR 2a (item 114): the Fixed basis, the assignment pool and Assign

PR 2 was split in two with the founder's agreement: 2a is the money core, 2b the joki catalog and checkout. No customer can buy joki yet.

- **Fixed basis.** `PricingBasis::Fixed` ("Fixed (joki)") and `OrderPricingResolver::resolveFixed(selling, share%)`: payout = `round(selling × share)` to the sen, platform = the rest, affiliate = 0. `Order::isJoki()` is the one predicate, and every path that could move the payout asks it:
  - resend keeps the cost and refuses another package;
  - a joki attempt after Failed/NeedsReview restarts on its package's supplier, the pool, inside `fulfill()`'s lock;
  - Manual Delivered refuses any other cost;
  - Bulk Markup skips joki games.

  The only writers of `orders.cost_price` are OrderFactory and OrderResendService (grep), and both are covered.
- **Catalog.** `games.is_joki`, and `AffiliateGame::hiddenGameIdsFor()` now always includes joki games. The affiliate portal's two copies of the rule were removed and route through it. The portal's game list leaves joki out, and its toggle 404s. A `not.joki` middleware covers every Games & Packages write route, and `GameFulfilmentRule::manualPackage` refuses a penjoki or the pool as a package supplier.
- **Pool.** `suppliers.joki_role` (`pool` or `penjoki`; one column instead of the ADR's `is_assignment_pool` flag), plus the pool row created by the migration. `Supplier::isAssignmentPool()` is read by:
  - the group post, the login link and the SLA reminder (none for the pool);
  - Delivered and Correct-to-Delivered (refused);
  - `setActive` (refused; F5);
  - `SupplierPackageStatusService` and group linking (refused);
  - the Dashboard "no group" and "not posted" counts and the Health supplier cards;
  - the Middleware and Partners supplier lists. The manual-package supplier picker leaves out every joki role.
- **Assign.** `JokiAssignmentService`, `POST /api/orders/{order}/joki/assign`, with a `joki_assignments` audit row. It takes the order lock, then the penjoki's lock, which is the same lock `setActive` now takes, so a penjoki with Pending orders can't be switched off (decision 19). Allowed for a Pending joki order only, to an active penjoki with a group, and never to the same penjoki.
- **Found while building.**
  - Reassigning A → B → A: the decision-17 key `(ref, supplier)` already had A's sent row, so A would get no post, and A's first link would have opened again. The key now ends `:assign:<id>` for an assigned order, and `supplier_login_links.joki_assignment_id` must match the order's current assignment. `customer_notifications.dedupe_key` widened 100 → 191.
  - The migration's `down()` first deleted the pool and failed on the packages FK during the MySQL concurrency teardown. It now deletes the pool only when nothing references it; otherwise it switches the pool off.
- **Verified.**
  - `php artisan test`: 2986/2987. The one failure, `SyncSupplierProductsCommandTest::test_command_syncs_every_active_supplier_via_its_own_adapter`, passed alone and in two runs of the Console suite. That command reads `apiBacked()` suppliers only, so the pool can't reach it.
  - New tests: `Adr126JokiCoreTest` (17), a portal test, and the route allowlist.
  - Concurrency suite: 35/39. The 4 are `BackupRestoreMysqlTest` (no local `mysqldump`). The new `JokiAssignConcurrencyTest` covers Assign vs the old penjoki's `.done` and Assign vs switch-off; both passed 4 runs in a row.
  - Admin `tsc` passed (`pricing_basis` gains `fixed`).
- **Left for PR 4 on purpose:** joki out of the Manual Queue and Need Action (F4), together with the Joki page and Dashboard card that replace them. Until then a pool order past the pool's SLA appears as stale.

## 2026-10-23 — Joki PR 2b (item 114): rank model, joki pricing and checkout

**Founder's answers (2026-10-23):**
- A division is worth its own stars: Epic V 0★ → Epic IV 0★ = 5 stars.
- Elite has 4 stars per division (the 2026 sources; it is panel data anyway).
- Mythic Grading is the 10 placement matches when an account first enters Mythic. Winning all 10 gives about 15 stars, which is why it is sold flat. Grading's flat price guarantees Mythic 15★: if the account lands lower, the penjoki plays on to 15★ at no extra charge, and only stars past 15★ are charged.

**Built:**
- **Tables.** `joki_settings` (one row), `joki_tiers` (rank model), `joki_routes` (route packages and quotations), `joki_orders` (snapshot) and `affiliates.joki_enabled`.
- **Seeded draft data.** The 2026 ladder (Warrior 3×3, Elite 4×4, Master 4×4, GM/Epic/Legend 5×5, then Mythic bands at 0/25/50/100) and the ADR's draft rates: below Mythic RM2 (Warrior/Elite/Master assumed the same as GM; the ADR had no figure), Mythic RM4, Honor RM5, Glory RM6, Immortal RM7, Express = +RM1, Grading RM39. Grading Express is seeded at RM39 too and needs the founder's figure. Joki stays off.
- **Pricing engine.** `JokiLadder` turns a rank into a star position, and validation errors are keyed `joki.from`/`joki.to`. `JokiPricingService::quote()` is the one price:
  - each star at the rate of the tier it is won in;
  - Grading added when crossing into Mythic;
  - at least `min_stars` paid stars, unless the order includes Grading;
  - a route package at its own price, Reguler only;
  - a quotation route is refused;
  - `cheaper_route` points to a route package selling the same climb for less.
- **Checkout.** One storefront checkout: `CheckoutController::sellableHere()` (the two copies of the game/package availability check in `store()` and `previewTotal()` are merged into it) takes a joki game past the hidden-game rule and through `JokiCheckoutService::resolve()`, which checks both switches on an owned brand, validates the role against the panel list, and takes the quote and `resolveFixed` at the share in force now. `CheckoutService` uses the pre-resolved pricing, `OrderFactory` writes `joki_orders` in the order's transaction, and the idempotency hash includes the climb (from, to, Express).
- **Endpoints for the `/joki` page.** `GET /api/joki/catalog` and `POST /api/joki/quote`, behind `brand.joki`. The payout share is never exposed.
- **Admin.** `AffiliateController`'s owned-brand-only rule is now one list (`membership_enabled`, `joki_enabled`).

**Found while building.** The first migration also seeded the joki game and calculator package. That broke 23 unrelated tests that count games or packages (SEO, game list, sitemap, reseller catalog, the CHIP smoke test), and it would have put a joki game into every catalog count on prod. The game and package are now made by `JokiCatalog::ensure()` (idempotent), which the admin's joki switch will call in PR 4. In the same file, PR 2a's `Game::scopeJoki()` had been inserted between `isLoginGame()` and its docblock; fixed.

**Verified.**
- `php artisan test`: 3005/3005, including `Adr126JokiPricingTest` (10, prices worked out by hand) and `Adr126JokiCheckoutTest` (8).
- Concurrency suite: the joki, finalize, fulfil and settle tests (5/5).
- e2e: 8/8. Dev DB migrated.

## 2026-10-23 — Joki PR 3 (item 114): progress, Track Order and the customer's WhatsApp update

- **One seam.** `JokiProgressService::record()` is used by the penjoki's `.progress` and by the admin's `POST /api/orders/{order}/joki/progress`.
  - Under the order lock: a Pending joki order held by a penjoki (never the pool), and for the bot only the penjoki it is assigned to.
  - It checks the rank against the panel model, then writes `joki_progress_updates`, `joki_orders.current_rank` and `manual_activity_at` (decision 21).
- **`.progress PG-… Epic III 2`.** `JokiRankParser` reads a division in Roman numerals or digits; a tier by name, last word, a 3+ letter prefix, or `GM`. For Mythic+ it reads the star count and takes the band from the stars (`Mythic 30` → Mythical Honor 30★). The bot answers in Malay, and the help text lists the command.
- **Customer WhatsApp (decision 10, S23).** `CustomerNotificationService::jokiProgress()`:
  - opted-in customers only (the receipt rule);
  - the first update goes at once; after that at most one message per `joki_settings.progress_message_interval_minutes` (default 120);
  - while one message is queued, a newer update adds nothing, and the message is worded at send time with the latest rank (`prepare()`, now called by `SendCustomerWhatsAppJob` for the customer session too);
  - dropped at send time if the order finished or the customer sent STOP.
  - `WhatsAppOutbox::enqueue()` gains `notBefore`.
- **Track Order.** `customerSafePayload()` gains `joki` (from, to, current, Express; labels only). `OrderObserver` also broadcasts on a `manual_activity_at` change, so an open page updates. The WhatsApp status card shows the climb and the current rank.
- **Storefront.** The Game & Package card shows "Climb" and "Rank now". A joki order no longer gets the login top-up copy ("usually within 30 minutes", "Your top-up is done").
- **Verified.**
  - `php artisan test`: 3015/3015 (`Adr126JokiProgressTest`, 10 new).
  - Storefront `tsc` and lint pass.
  - A temporary e2e spec (since deleted) created a joki order in the throwaway e2e DB and opened Track Order. Screenshots at 1280 and 390 wide showed the climb, the current rank and the joki help text, with no horizontal scroll.

## 2026-10-23 — Docs sync after Joki PR 1–3 (#536 merged)

Checked against `git log origin/main..origin/staging` and read-only prod: payment methods (FPX on since 2026-10-22 09:52 UTC, FPX Business and DuitNow QR off) and the last migration (2026-10-22's, so nothing from #529–#536 runs on prod yet).

- **PRD §14.** New "On `staging`, not released" paragraph (#529–#536). It says plainly that the item 130 fix isn't live, and that Joki PR 1 also changes Login Top-up.
- **PRD §15.** New Joki MLBB row.
- **PRD §16.** Items 114 and 130 updated; 130's closed line now says "not released".
- **PRD §6.24 and §6.25.**
  - LOGIN-6: per-login ceiling.
  - MSUP-3/4/5/6: supplier re-check, posts per supplier and assignment, `.progress`, SLA up to 4320 and the explicit clock.
  - New §6.25 Joki (JOKI-1..10). MSUP-5 already pointed at it.
- **PRD §8.** New columns on Game, Supplier, Affiliate, Order (`fixed` basis, `manual_activity_at`), OrderCredential, SupplierLoginLink and CustomerNotification, and six joki entities.
- **PRD §13 Glossary.**
  - Joki no longer says "Designed, not built": the Glossary never states build status (the `adr.md` rule).
  - Funding Mode cites ADR-126 for penjoki.
  - New terms: Assignment pool, Mythic Grading.
- **PRD §1 and §10.** The CHIP lines no longer repeat "all three methods off since 2026-10-07", which contradicted §14. Payment status now lives in §14 only.
- **ADRs.** Standing updated, index and banner together:
  - ADR-126: Accepted, with the later addenda;
  - ADR-116: joki progress pacing;
  - ADR-061: the owned-brand rule gates Joki.

  ADR-126's PR 2a notes now record that the game flag is `games.is_joki`, not the original `owned_brands_only`.


## 2026-10-24 — Joki PR 4a: the admin backend (ADR-126 decisions 23-25, F4)

PR 4 is split like PR 2 (founder, 2026-10-24): **4a** is the backend and its tests, **4b** the admin screens.

- **`JokiBoard`** (`app/Services/Joki`) defines the Joki page's filters and the Dashboard card in one place, over paid, real joki orders:
  - `waiting`: Pending on the pool;
  - `in_progress`: Pending with a penjoki;
  - `stale`: past its SLA (`isStalePending`), or NeedsReview;
  - `needs_voucher`: `needsAction()`;
  - `done` and `failed`.
- **F4.** `Order::scopeJoki()` / `scopeNotJoki()` (Fixed basis) take joki out of:
  - `/orders` Need Action (filter and summary);
  - the Dashboard's `needs_action_orders` and stale-pending part of `stuck_orders`;
  - the Manual Queue.
  - **Founder's choice (2026-10-24):** a Failed, uncompensated joki is the card's 4th number, "needs voucher", so it is never left with nowhere to be seen.
  - Reports' "awaiting compensation" keeps joki: it is money, not a work queue.
  - `needs_review` stays general.
- **`/orders?type=all|topup|login|joki`** (decision 25):
  - login = has an `order_credentials` row;
  - joki = Fixed;
  - the export's scope line names the type.
- **`/api/joki-admin/*`** (`JokiAdminController`):
  - every admin: `orders` (paginated board rows: ranks, penjoki, payout, last activity, SLA due) and `summary`;
  - every admin: `penjoki` (open orders, `owed_sen` from the pay-after ledger, has group);
  - super admin: `settings`, `tiers` and `routes`.
  - Assign and progress stay on `JokiAssignmentController`. Recording a payment is the existing supplier-transfer action.
- **Settings.**
  - Whole ringgit is enforced by `integer` rules. Share: 0–100, two decimals. Grading Express is never below Grading. The Mythic cap must be above the highest band. `terms_html` is Purified (`rich_text`).
  - **`is_enabled: true` calls `JokiCatalog::ensure()`.** Off only stops new sales; the pool stays active for paid orders.
- **Rank model.**
  - Only existing tiers are edited (adding or removing a tier is not built). Express is not below Reguler.
  - The Mythic bands must start at 0★ and climb.
  - Every route must still read on the new ladder, or the whole edit rolls back.
- **Route packages.**
  - A new route is a joki `Package` on the pool (price fields 0, F1) plus its `joki_routes` row.
  - The climb is checked on the ladder: the target must be above the start.
  - A route is switched off, never deleted, because past orders point at its package. A quotation clears the price.
- **Penjoki** are created in Middleware → Suppliers: `joki_role: penjoki` is accepted only on a pay-after manual supplier, and the pool can't be made there.
- **Banner and "Joki Rank" menu.** No new setting. The banner is an ordinary Hero Slide linking to `/joki`, and the menu item is PR 5's, shown when `/api/joki/catalog` answers.
- **Verified.**
  - `php artisan test`: 3026/3026 (`Adr126JokiAdminTest`, 11 new; `RouteAccessTest` lists the three admin-readable routes).
  - On the dev DB, `notJoki()` keeps all 189 existing orders.

## 2026-10-24 — Joki PR 4b: the admin screens (ADR-126 decisions 16, 23-25)

Stacked on PR 4a (#538).

- **Admin → Joki** (`/admin/joki`, sidebar under Commerce):
  - **Orders:** `JokiBoard`'s filters (All, Waiting, In progress, Stale, Needs voucher, Done, Failed). Each row shows the climb with the current rank, role and hero, the penjoki, time waited, last activity, SLA left or overdue, and sold price and payout. Assign is inline for Pending rows; other rows open Order Detail. `?filter=` deep-links from the Dashboard card.
  - **Penjoki:** open orders, owed and group status. Record payment opens the existing `SupplierTransferModal` (its prop narrowed to the six fields it reads). Super admin only, like Accounting.
  - **Settings** (super admin):
    - the Joki switch: the first time it is switched on, it creates the joki game and calculator package;
    - prices and rules;
    - roles, Express wording and terms (`RichTextEditor`);
    - the rank model (names, shape, whole-RM rates, icons from the Gallery);
    - route packages (add, change the price, switch off).
- **Order Detail Joki card.**
  - The order detail response gains `joki`: ranks, requests, payout share, `is_with_penjoki`, Assign and progress history, and the rank model for the picker.
  - For a Pending order, the card has Assign or Move, plus Update progress (penjoki only) through a shared `RankPicker`.
- **Dashboard Joki card:** waiting, in progress, stale, and failed needing a voucher. Each number links to its board filter, and urgent non-zero numbers are red. The card is hidden while all four are 0, so it doesn't sit empty before launch.
- **`/orders` Type select** (All / Top-up / Login / Joki). It accepts `?type=` and is part of the export filter.
- **Brand form:** "Sell Joki on this storefront" (owned brands only), next to Membership. The brand list tags it "joki".
- **Middleware → Suppliers create form:** an "A penjoki" tick on a pay-after manual supplier sends `joki_role: penjoki`.
- **Backend touch-ups:**
  - a tier icon accepts any URL (local Gallery URLs are `http`);
  - a price with sen is refused with "Whole ringgit only, no sen."
- **Verified.**
  - `php artisan test`: 3027/3027. Admin: `tsc`, lint and `npm test` (17) pass, and `next build` succeeds.
  - Browser check: built admin on `localhost:3000`, against a copy of the dev DB in the scratchpad (seeded with three joki orders and a penjoki; queue `sync`; OpenWA pointed at a dead port). The real dev DB was not touched.
  - Flows checked:
    - board, Assign, the Order Detail card and the Penjoki tab;
    - Record payment modal;
    - settings save (sen refused, Grading Express saved);
    - route add;
    - Dashboard card, and the `/orders` Joki filter (3 rows).
  - At 390 px wide there is no horizontal page scroll; the table scrolls inside its card.
  - Fixed during the check: a Failed row read "Waiting" for its penjoki, status tags were lowercase, and order numbers wrapped on mobile.

### 2026-10-24 — PR 4b restyled on `/admin/orders`; board filters (founder)

The founder found the first Joki screens untidy and unlike `/orders`, and asked for `/orders` as the benchmark plus the same filters.

- **Shared components, not copies:**
  - `StatusText` and the delivery/payment severity maps moved out of `orders/page.tsx` into `components/orders/StatusText.tsx`;
  - `OptionSelect` moved out of `CreateSupplierModal` into `components/ui/option-select.tsx` (with a placeholder).
  - Joki uses them with `DataTable`, `Input` and `Label`. No native selects or hand-rolled inputs are left.
- **Orders tab:**
  - five summary cards in `OrderSummaryCards`' style (Waiting carries the purple marker; All Joki uses a new `all` count from `/summary`);
  - search with the underline filter tabs, and a filter row: penjoki, plus the same date presets as `/orders`;
  - dot status text, and a two-line Climb / Waiting+SLA / Amount;
  - View on every row, and Assign (in a dialog) on unassigned rows.
- **Backend:** `/api/joki-admin/orders` takes `search` (order # or email), `supplier_id`, and `date_from`/`date_to`, with the same KL-day bounds on `created_at` as `/orders`. One new test.
- **Penjoki tab:** in `DataTable`. **Settings:** cards in Order Detail's style, `Input`/`Label`, and the rank model and routes in bordered tables. **Order Detail Joki card:** laid out like `OrderDetailCards`. **Dashboard card:** summary-card style.
- **Verified:** built admin against a seeded copy of the dev DB, with screenshots next to `/orders`. Assign through the dialog, search `DEMO3` returning 1 row, and no horizontal scroll at 390 px all passed. `tsc`, lint and tests pass.

## 2026-10-24 — Joki PR 5: the `/joki` page (ADR-126 decisions 1, 2, 3, 12)

#538 (4a) and #539 (4b) were merged to `staging` with the founder's go-ahead. #539 was retargeted to `staging` before #538 was merged with `--delete-branch`.

- **`/joki`** (`app/joki/page.tsx`, `components/joki/JokiOrderForm.tsx`) follows the top-up order page. It reuses `Stepper`, `StepCard`, `LoginAccountFields`, `OrderSummarySidebar`, `ReviewModal` and the sticky mobile bar, with three steps:
  - **Choose your climb.** A calculator (`JokiRankStepper`) for any climb, or route packages. Quotation routes link to WhatsApp.
  - **Account & requests.** The joki game's login form, consent, role, hero and play window.
  - **Payment.**

  The price is always the backend's: `/api/joki/quote` for the climb, and `preview-totals` with the `joki` block for the fee and total. Checkout sends the `joki` block and never a price. It answers 404 where the brand doesn't sell joki.
- **Rank calculator (founder, after comparing rapspoint.com).**
  - ‹ › move one division or Mythic band; ▲ ▼ set the stars, which are kept across divisions as far as they fit.
  - Mythic stars can be typed, and the band follows them.
  - The target never goes below the start, and moving the start up pushes it along.
  - It opens on Epic V → Epic IV, priced at once. Both sides sit side by side on a phone too.
  - Icons come from the panel's rank model, with a lettered badge until the founder's icons are set.
- **Shared, not copied.** `PaymentChannelPicker` was moved out of `OrderForm` unchanged. The login-field wire schema and its mapping are exported from `lib/catalog.ts`.
- **Menu and sitemap.** "Joki Rank" is in the header and the mobile More drawer, and `/joki` is in the sitemap, all only where `/api/joki/catalog` answers. `BottomNav` treats `/joki` as a checkout (the sticky bar takes its place).
- **Fix (found building it): the voucher preview priced the joki package's RM0 (F1).** `VoucherPreviewController` now prices a joki game through `JokiCheckoutService` and takes the `joki` block. `ReviewModal` passes it. There is a red-to-green test.
- **Cache.** Joki settings, rank model and route saves, and a brand's `joki_enabled` change, call `NextRevalidation::purge()`.
- **E2E golden path** (`storefront-joki.spec.ts`):
  - the admin switches Joki on and ticks "Sell Joki";
  - a guest prices a climb (RM10, then one more star RM12) and pays;
  - the webhook comes in, and the order waits on the pool;
  - the status page shows the climb, and the board shows it Unassigned.
- **CI catch:** `check-loading-boundaries.mjs` failed because `/joki` had no `loading.tsx`, so navigation would freeze. It now reuses `OrderPageSkeleton`, since the page has the order page's shape. Run that script locally before pushing a new storefront route.
- **Gotcha: the e2e storefront's Data Cache survived rebuilds while the DB is rebuilt every run.** A cached catalog from an earlier run named a package that no longer existed ("The selected package id is invalid"). The storefront `webServer` command now clears `.next/cache/fetch-cache` first. This also explains a flaky "Joki is not available" seen earlier the same day.
- **Verified.**
  - `php artisan test`: 3029/3029.
  - Storefront and admin: `tsc`, lint and build pass.
  - e2e: 9/9 in the full run (and repeatedly before the calculator change).
  - Screenshots of `/joki` on desktop and at 390 px (no horizontal scroll), the Review Modal, the status page and the admin card.

## 2026-10-24 — #540 merged (Joki PR 5); session paused

- **#540 merged to `staging` (`53ade00`)** with the founder's go-ahead, after CI went green on its second run. The first failed only on `check-loading-boundaries.mjs`, fixed with the skeleton. Every Joki PR (1–5) is now on `staging`, not released. Joki is off on prod until its launch gates are met (PRD §16 item 114).
- **Local only:** the founder's dev DB has Joki on, "Sell Joki" on PekanGame, and the Epic tier's icon (a founder-supplied image, through the Gallery ingest).
- **Open, not diagnosed:** in the founder's Chrome, every request to `https://kedairuncit-backend.test` fails with 503 or "Failed to fetch", so `/joki` shows broken icons and no price. `curl` to the same URLs gets 200, the `.test` resolver and Herd's nginx are fine, there is no system proxy, and the Herd nginx log is empty, so the requests seem not to reach Herd.
  - A headless check with `--ignore-certificate-errors` hid this. Verify local UI in the founder's own browser, not only headless.
  - Picked up next session.

## 2026-10-24 — ADR-127 designed (item 131 widened); PR A: real-cost flag removed, financial scenario builder

- **Design (#542, merged).** The founder widened item 131 from "revenue by delivery date" to every money page. A three-layer read-only trace (checkout and pricing; ledger, fulfillment and compensation; downstream readers), the grill (Q1–Q13) and a stress test produced [ADR-127](./adr/ADR-127-financial-truth-dated-facts.md). Its rule P1: a figure for any past period comes only from dated facts that never change. It revises ADR-083, ADR-111 decision 8 and ADR-126 decisions 11/13.
- **Production, read-only, 2026-10-24:**
  - 46 orders, 32 of them Delivered.
  - No order was paid in one month and delivered in another.
  - September is the only closed month.
  - There are no duplicate supplier-ledger references.
  - `REAL_COST_RECONCILIATION_ENABLED=true`.
  - So no data patch is needed for ADR-127.
- **PR A (`feature/adr-127-pr-a-scenario-builder`):**
  - **The real-cost flag is removed (ADR-127 decision 10).** Real cost is the only path. Five tests failed once it was gone:
    - Four combo tests wrote the supplier's `price` in sen (`480`), while adapters return ringgit. The off default had hidden this.
    - One Digiflazz webhook test asserted the checkout estimate, under a comment saying `price` is "never used". In production it has been the real cost since ADR-111.
  - **`Database\Seeders\FinancialScenario\FinancialScenario`** builds money data by driving the real system:
    - Checkout, the CHIP webhook and the Reseller API through the HTTP kernel, so prices are resolved server-side.
    - Back-office actions through the admin's services.
    - The API supplier is a `ScriptedSupplierAdapter`. By default it returns the catalog cost in ringgit, and a scenario can queue a failure or a different real cost for the next order.
  - **The builder is safe by construction:**
    - It refuses to run in production.
    - The queue is `sync`, so no Horizon worker can reach a real supplier.
    - Outbound HTTP is refused, and mail and notifications are faked.
    - The world is created at the scenario's start time.
  - **`FinancialScenarioTest` proves invariants 5, 6 and 10** on that data:
    - Wallet and affiliate balances agree on Dashboard, Summary, Cash Position, the portal and the API.
    - The price identity holds with real cost.
    - Every delivered API order has exactly one drawdown.
  - The other invariants arrive with the PR that makes them true. `FakeSupplierAdapter` (sandbox, e2e) is unchanged.
- **Gotcha:** the first version created the reseller's opening top-up at the real clock (October). September's Cash Position correctly left it out, and invariant 5 read -RM22.10. Scenario data must be created on the scenario's own clock.
- **Verified:** `php artisan test` passes 3032/3032, and Pint is clean.

## 2026-10-24 — ADR-127 PR A merged (#543); PR B1: revenue at `recognised_at`

- **#543 merged** with the founder's go-ahead, after CI went green.
- **PR B split** (ADR-127 2026-10-24 addendum). This is **B1**.
- **Red first.** `FinancialScenarioTest` gained an order paid on 30 September at 23:50 and delivered at 00:10 on 1 October (a scripted Pending, finished through `finalizePendingDelivery`).
  - Before the fix, September's paid money (RM74.84) showed as recognised plus owed = RM101.79: the order counted twice (S1, proven).
  - October's revenue did not contain the order at all.
- **Fix:**
  - `orders.recognised_at` (indexed, backfilled), stamped once in `creditOrderProfit()`.
  - `RecognisedRevenue`, affiliate commission and Cash Position's undelivered claim read `recognised_at`.
  - The Reports Bridge gained "Recognised here, paid in another range". Admin `AccountingBridge` shows the new row.
- **Invariants 1 and 4 now hold.** Paid, uncompensated money is either recognised or owed at every month end.
- **Local dev DB:** migrated (plain `migrate`). 167 of 167 Delivered orders recognised, none moved month.
- **Verified:** `php artisan test` passes 3035/3035; admin `tsc` and lint pass.

## 2026-10-24 — ADR-127 PR B2: dated facts everywhere, closed months that cannot move

- **Stacked on B1 (#544).** Every step below went red first, then green:
  - **CHIP's own paid time and fee (F1, F2).** `paid_at` was our processing time, so a late webhook moved a 23:58 payment into the next month. It is now `payment.paid_on`, with a fallback to processing time when the value is missing, implausible, or in a closed month. The fee is kept as `gateway_fee_sen`.
  - **One date per fact (F4, F5).** Restores carry `restored_at`. The top-up and membership CHIP-unsettled asset is dated by the same ledger fact as the wallet liability; the attempt's `updated_at` is no longer read.
  - **Closed-month guard.** Transfers, transfer corrections and voids, and envelope postings and voids refuse a closed month. A void used to silently rewrite the month it voided.
  - **Breakage at expiry (S7).** `Voucher::balanceAsOf()` is now shared with Cash Position.
  - **Invariant 2.** The scenario closes September, then keeps trading. The new invariant caught the last Summary line reading current state: the reseller wallet balance (S10), now taken at the month end. The Register gained `order_recognised` rows (S2).
- **Gotchas:**
  - Several test fixtures were impossible in production, and were masked until now:
    - a voucher whose `remaining` doesn't match its redemptions;
    - a "Paid" top-up with no wallet credit;
    - Delivered orders with no recognition.
  - A wallet test had asserted the old "always today's balance" rule. It now asserts the month-end rule.
- **Local dev DB:** migrated (`gateway_fee_sen`, `restored_at`).
- **Verified:**
  - `php artisan test` passes 3046/3046.
  - Admin `tsc` and lint pass.
  - Pint is clean.

## 2026-10-24 — ADR-127 PR C: one truth across the money pages

- **Stacked on B2 (#545).** Everything below went red first (see the ADR-127 PR C addendum for details):
  - **Profit by when it was earned.** Reports, the Report Assistant (`recognised_at_kl`, `placed_via`) and the Summary's commission now file profit by recognition. A manual correction is filed by the day it was made.
  - **One store credit.** Reports now matches Cash Position (`Voucher::outstandingAt`).
  - **One membership source** (the ledger), plus the missing `is_test` filters.
  - **Names.** Sales, Revenue (recognised), Owner profit (market rate), Customer spend. The Overview chart had called paid-date sales "Revenue".
  - **A profit bridge** from owner profit to operating profit on the Profit tab, with nothing unexplained.
  - **The settlement fee check.** The fee CHIP reported at payment is shown beside the settlement file's fee.
- **Findings fixed in this session** (founder rule: finish findings in session):
  - A later manual profit correction moved a past month's Reports profit.
  - A voucher revoke left no date, so Cash Position kept owing a revoked balance forever. It is now a locked, dated `VoucherService::revoke()`, and its unspent balance is breakage of the revoke month.
- **Local dev DB:** migrated (`revoked_at`, `llm_report_orders` view, `reported_fee_sen`).
- **Verified:**
  - `php artisan test` passes 3056/3056.
  - Admin `tsc` and lint pass.
  - The browser pass over every money page is still to do, with the full data set (ADR-127 PR E).

## 2026-10-24 — ADR-127 A, B1, B2 and C merged to `staging`; docs synced

- **Merged with the founder's go-ahead, each after green CI:**
  - #543 (PR A);
  - #544 (B1);
  - #545 (B2), retargeted to `staging` before its parent merged;
  - #546 (C), also retargeted first.

  Every one is on `staging` and unreleased; the release waits for the founder.
- **Docs sync.**
  - PRD §14 lists #529–#546 and the six ADR-127 migrations.
  - The §15 rows for Reports, Vouchers, Customer Analytics and Internal Accounting carry the ADR-127 notes.
  - The §16 item 131 entry and its detail text are rewritten (the old "pick a start month" step was not needed).
  - ADR-024, ADR-083, ADR-087 and ADR-104 have Standing and dated addenda for what ADR-127 revised.
- **Next session: ADR-127 PR D.**
  - Mark Delivered goes through the drawdown seam, with a unique index.
  - Delivered is refused on a compensated order.
  - Penjoki pay is booked as cost of sales.
  - Joki is counted once on the Dashboard.
- **Then PR E:**
  - a dev-DB seeder;
  - delete `ReportDemoSeeder`;
  - a browser pass over every money page.

## 2026-10-24 — ADR-127 PR D: money flows

- **Founder decisions (grilled in Malay):**
  - Mark Delivered takes the supplier's charged price.
  - There is no partial or extra penjoki pay. Decision 8 counted that cost twice: an envelope expense already reduces its envelope, and the operating profit leaves envelope expenses out (ADR-127 2026-10-24 addendum).
- **Built, each test red first:**
  - **Mark Delivered (NeedsReview).** The admin enters `supplier_price`, in the supplier's currency, read off its dashboard. It becomes the real cost and the one drawdown. The path had recorded no drawdown.
  - **The drawdown is unique in the database.** A virtual `drawdown_key` column is unique for `ORDER_DRAWDOWN` only, so repeated transfer corrections stay allowed. On prod, read-only today: 35 drawdowns, no duplicate.
  - **Delivered is refused on a compensated order.** This happens at `finalizePendingDelivery()` (Manual Queue, `.done`, a webhook) under the lock, and in `markDeliveredManually()`. A Paid-after-Failed order with a restored voucher could have reached Delivered through Manual Queue or `.done`.
  - **The Dashboard's stuck count leaves out a joki in NeedsReview**; the Joki card counts it.
  - **`FinancialScenario`** gains a NeedsReview order confirmed above catalog cost, covered by invariants 6 and 10.
- **Gotchas:**
  - MySQL reads `||` as OR, so the virtual column's expression is per driver.
  - MySQL JSON reorders keys, so a JSON `assertSame` passed on sqlite and failed on MySQL.
  - A killed MySQL run left the `llm_report_*` views behind (backend gotcha 8).
- **Combo legs need no guard.** Traced: a combo can be compensated only once no leg is Pending, and a resend is refused afterwards.
- **Local dev DB:** migrated (`drawdown_key`).
- **Verified:**
  - `php artisan test` passes 3061/3061, including the new tests.
  - The PR D service tests and `FinancialScenarioTest` pass on MySQL (35/35).
  - Admin `tsc` and lint pass.

## 2026-10-24 — ADR-127 PR E: dev data, browser pass, two findings

- **#548 (PR D) merged** to `staging` with the founder's go-ahead, after green CI.
- **Joki in the scenario:** delivered, failed (the penjoki is owed nothing), reassigned (only the second penjoki is paid), and a joki paid in September and finished in October. A new invariant covers the penjoki payout.
- **`FinancialScenarioSeeder`** fills the dev DB with four months (about 1,500 orders) through the real system. `ReportDemoSeeder` is deleted (ADR-088 addendum).
- **Local dev DB** (founder: "buang je"):
  - backed up to `backend/database/database.sqlite.bak-before-pr-e-2026-10-24`;
  - 189 `DEMO-` orders, their 199 ledger rows and one voucher removed;
  - the seeder run: 1,578 orders, no price-identity or drawdown exception, 0 unexplained on the bridge every month, the September close equal to its live figure.
- **Gotchas:**
  - The first seeding runs failed with "database is locked": `./scripts/dev.sh` (Horizon) was on the same sqlite file. Claude stopped it at the founder's request, restored the backup, removed `DEMO-` again, seeded, and restarted it.
  - The database cache store kept rate-limit hits from an earlier run, because the scenario replays the clock. The scenario now uses the array cache.
  - Restarting `dev.sh` let the storefront take :3000, which broke admin login (its cookie works only on :3000). The admin and storefront ports are now pinned.
- **Browser pass:** 11 money pages, with no API error. Two findings, both fixed:
  - **A pay-after supplier payment was counted as a prepaid top-up** on the Monthly Summary. It now has its own line, "Supplier payments — pay-after". Production, read-only: no pay-after payment yet.
  - **The Joki "Waiting" column** showed a finished order's age since payment.
- **Verified:**
  - `php artisan test` passes 3064/3064.
  - Admin `tsc` and lint pass.
  - The Summary's split was checked in the browser: October's prepaid RM12,000 and pay-after RM1,170 add up to the old RM13,170.

## 2026-10-25 — ADR-127 PR E completed: the six untested flows, two findings

- **Founder question:** were the accounts correct? Claude listed what the scenario did not drive yet (membership, CHIP top-ups, withdrawals, resends, an IDR supplier, CHIP settlement with the bank). The founder asked why they weren't tested with the rest. Claude had scoped them out without raising it. They are now in #549.
- **Built:**
  - The `MoneyFlows` trait and a `ChipSettlementFile` writer; the bank modelled as dated flows.
  - Five new invariants: the cash equation closes with a 0 gap, membership revenue agrees, IDR costs at funded rate, CHIP fees recorded, resend and withdrawal.
  - The seeder drives all of it.
- **Finding 1, fixed:** the membership gate ran before the brand resolver, so an affiliate's domain was gated by the platform's switch (ADR-080 2026-10-25 addendum). Production: "FixFast Demo" (off) had the surface open; no attempt there.
- **Finding 2, pending (§16 item 132):** IDR COGS at a cumulative average leaks between months (RM4.01 over three months on the dev data). The founder chose a moving average, after a grill.
- **Gotchas:**
  - `membership_checkout_attempts` and `wallet_topup_attempts` have no `paid_at`. On sqlite, `where('paid_at', …)` compared a string and returned nothing, silently.
  - A first invariant formula ("gap = Σ fee − RM1 × count") passed by coincidence, while memberships were never settled. The true invariant is a 0 gap.
- **Dev DB:** restored from the backup, `DEMO-` removed again, reseeded. The result:
  - 1,590 orders, 53 members, 115 settlements, 4 withdrawals;
  - no price-identity or drawdown exception;
  - the bridge leaves 0 unexplained every month.
- **Verified:**
  - `php artisan test` passes 3070/3070.
  - 11 money pages in the browser, no API error. The October Summary adds up to its operating profit by hand.

## 2026-10-25 — #549 merged; item 132 grilled and designed

- **#549 (ADR-127 PR E) merged** to `staging` with the founder's go-ahead, after green CI. Every ADR-127 PR (#542–#549) is now on `staging`, unreleased.
- **Item 132 grilled in Malay** (Q1–Q12) and stress-tested. It is ADR-083's 2026-10-25 addendum: foreign-currency deposits at a moving weighted average from 1 October 2026, a replayed pool opening at September's close value, a drawdown's cost fixed when drawn, and voided transfers with all their rows left out.
- **Production facts, read-only:**
  - three live Digiflazz deposits, all in September;
  - the running IDR balance never negative (41 rows);
  - the two voids of 15 September were written as `MANUAL_ADJUSTMENT` rows (−1,224,819 IDR).
- **Founder:** build in the next session.

## 2026-10-25 — Item 132 built: IDR deposits at a moving weighted average

- **What:** ADR-083's 2026-10-25 addendum, built on `feature/item-132-idr-moving-average` (#551).
  - `MovingAveragePool` replays a foreign-currency supplier's pool from 1 October 2026. It opens with the units at 30 September at that day's cumulative average, which is September's close value, so September does not move. A deposit adds its net units and ringgit at the start of its `transferred_on` day. Every other row takes `round(pool_sen × units ÷ pool_units)`. An empty pool uses the last known average.
  - A voided transfer and every row that references it are left out.
  - `SupplierRowValuer` costs a row the same way for every reader: COGS, drawdowns not in COGS, and the Summary's supplier corrections line. `CashPositionService` reads the pool's month-end value. An MYR supplier and any row before the opening keep the old rate.
- **Test first:** `FinancialScenarioTest` gained IDR deposits at RM1/3,500 IDR (October) and RM1/3,900 IDR (November), plus a November balance correction. On the old method the October close had a gap of −33 sen. It is now 0 for both months. `MovingAveragePoolTest` checks the arithmetic by hand: conservation to the sen, the empty pool, the opening and month-end cut, the voided transfer, and a correction at the average.
- **Not done yet:** merge, release, then a read-only check that prod October moves only a few sen.
## 2026-10-25 — Joki offered from Epic up

- **Founder:** offer joki from Epic up and drop Warrior, Elite, Master and Grandmaster. The founder chose a migration over a per-tier panel switch (ADR-126 2026-10-25 addendum).
- **Built** on `feature/joki-epic-and-up`:
  - The migration `2026_10_25_100000_remove_joki_tiers_below_epic` deletes the four tiers. It refuses if a route references them; `down()` restores them.
  - The `GM` alias is removed from `JokiRankParser`.
  - Tests now expect six tiers starting at Epic.
- **Verified:**
  - `php artisan test`: 3070/3070.
  - The local dev DB was migrated and now has Epic, Legend and the four Mythic tiers.
  - The local `/api/joki/catalog` lists Epic first. A quote from Epic V 0★ to Legend V 0★ is 25 stars × RM2 = RM50.
  - The storefront and admin read the tiers from the API, with no tier name hardcoded. There was no browser screenshot, because local Chrome to `.test` is still broken.

## 2026-10-25 — Dashboard System details in plain words

- **Founder:** the (i) tooltips on System details showed developer text (column names, class names), which an admin can't act on. Rewrite them for an admin.
- **Built** on `fix/dashboard-plain-tooltips`:
  - The seven definitions now live in `DashboardService::DEFINITIONS` as plain English. Each says what the figure is and what to do about it; the exact rule stays in the code that computes it.
  - A late scheduled task now shows "Supplier balance refresh", not `supplier-balance-refresh`.
  - The scheduler's raw "failed with exit code [1]" line becomes "stopped with an error on its last run (details in the server log)". The raw text is kept in the hover title.
- **Verified:**
  - Dashboard tests pass (40), along with admin `tsc` and lint.
  - Local screenshot of the panel with the suppliers tooltip open and a late task.
  - Locally, `supplier-balance-refresh`'s heartbeat was set late on purpose to show the error line.

## 2026-10-25 — Release #554 live (`staging`→`main`, #529–#553)

- **Before release:**
  - Prod `/api/health` had been 503 since 25 Oct 00:00 UTC. `supplier-balance-refresh` failed on a Gamevion `check-balance` SSL timeout (cURL 28), which aborted the sweep before Digiflazz. This is item 130's failure; its fix (#531) was on `staging` only.
  - Read-only checks: 0 duplicate drawdowns, so the unique `drawdown_key` index was safe. October snapshot: COGS 38,506 sen, Digiflazz prepaid 18,549 sen.
- **Merged** with the founder's go-ahead:
  - #551 (item 132);
  - #552 (joki from Epic up), after a docs conflict with #551 was resolved by keeping both;
  - #553 (plain tooltips), same kind of conflict;
  - then release #554 as `0d6b8ad`. CI and `deploy` were green.
- **Box, read-only:**
  - release `81129239` is live, and all 14 migrations ran;
  - Horizon is running;
  - `joki_tiers` = Epic, Legend and the four Mythic tiers; joki is off;
  - October COGS and prepaid are unchanged. The pool matches the old method to the sen, at 797,643 IDR = 18,549 sen, because there has been no deposit since September.
- **Still red:** `/api/health` stays `scheduler: false` until `supplier-balance-refresh` succeeds. `schedule:test` would not clear it, because it runs the event without firing `ScheduledTaskFinished`, so no heartbeat is written. It clears at the next daily run (00:00 UTC), now with the fix live.

## 2026-10-25 — Affiliate vs Reseller, one place per order; Joki rank model save; clear upload errors

- **Founder findings:**
  - Reports → Partners showed PekanGame at RM563.72 / 37 orders. Its storefront sold only RM110.60 / 22; the rest were reseller-wallet orders (Naeem RM421.71), also listed under Resellers.
  - Customer Analytics' Affiliate filter had the same fault.
- **Fix (ADR-086 2026-10-25 addendum):** `Order::scopeSoldByAffiliate()` and `affiliateKeySql()` hold the rule. It now applies to:
  - Reports (`scopedOrders`, `profitTotals`, `profitByGroup`, `affiliateBreakdown`);
  - Customer Analytics' filter;
  - the Affiliates list count;
  - the portal sales aggregate;
  - the `llm_report_orders` view (migration `2026_10_25_110000`) and the Report Assistant's column note.
- **Accounting is not affected** (read-only on prod): wallet orders have `affiliate_profit` 0, and their 11 affiliate ledger rows are RM0.
- **Joki rank model would not save** ("tiers.2.divisions must be an integer"). The admin sent every shape field for every tier, so Mythic went with `divisions: null`. The new `tierUpdate()` (in `admin/src/lib/joki-tiers.ts`, unit-tested) sends only a tier's own kind's shape. A backend test saves all six tiers as the page sends them. The bug had been there since PR 4b.
- **Gallery "Upload failed":**
  - nginx's default 1 MB body limit refused 2 MB and 12 MB images with a 413, before Laravel. That response has no CORS headers, so the admin saw only a network error.
  - The founder set Forge's Max File Upload Size to 12 MB. Verified on the box: `upload_max_filesize`/`post_max_size` 12M, and `/etc/nginx/conf.d/uploads.conf` sets `client_max_body_size 12M`.
  - `apiUpload` now refuses a file over 12 MB by name and explains a refused upload. The gallery names a file over its own 5 MB before uploading.
- **Verified:**
  - `php artisan test` 3079/3079, and the invariant test was red first.
  - admin `npm test`, `tsc` and lint.
  - Local browser: the rank model `PUT` returns 200; a 6 MB file shows "big-photo.png is 6.0 MB. Each image can be up to 5 MB."; Partners shows PekanGame at 1,046 storefront orders (243 wallet orders no longer counted).

## 2026-10-25 — Release #557 live (#556); the day in one place

- **Release #557** (`staging`→`main`, founder's go-ahead), merged as `369fbbb`. CI and `deploy` were green.
- **Box, read-only:**
  - release `81131278` is live; migration `2026_10_25_110000` ran; Horizon is running;
  - the `llm_report_orders` view has 0 rows with both an affiliate and a reseller;
  - all-time Partners, before → after: PekanGame RM563.72 / 37 → RM110.60 / 22. FixFast Demo is RM8.19 / 4; Naeem RM446.56 / 11 and FixFast RM6.56 / 4 stay under Resellers.
  - Brands plus resellers = RM571.91 = total sales. Before, they added up to RM1,025.03.
- **The day, in order:**
  1. Item 132 (IDR moving average) built test-first, #551.
  2. Joki offered from Epic up, #552.
  3. Plain Dashboard tooltips, #553.
  4. Release #554, which also carried Joki (off), ADR-127 and the item 130 fix.
  5. The founder found the Joki rank model wouldn't save, gallery uploads failed, and wallet orders were counted under PekanGame. All three were fixed in #556 and released as #557.
- **Founder actions done:** Forge Max File Upload Size set to 12 MB.
- **Open:**
  - `/api/health` is 503 until `supplier-balance-refresh` runs at 00:00 UTC on 2026-10-26, now with the item 130 fix live.
  - The Joki launch gates (§16 item 114): CHIP approval, item 129, rank icons, and the rates with the Grading Express price.
- **Lesson:** a figure that "groups by brand" must say which orders a brand owns. The wallet order's primary-brand `affiliate_id` (ADR-073) had been handled correctly in three views and wrongly in five. The rule now lives in one place: `Order::scopeSoldByAffiliate()`.

## 2026-10-25 — Items 71 and 96: GSC recheck, OpenWA cleanup and retention

- **Item 71, closed (GSC, property `pekangame.com`, data to 16/10).** ADR-120's duplicate and canonical reasons are gone. 2 pages indexed. 3 "Page with redirect" are `http://pekangame.com/`, `http://www.` and `https://www.` → apex (intended). 42 "Discovered – currently not indexed", all never crawled. `robots.txt` allows them, the pages answer 200 with a self canonical, and the sitemap (50 URLs) was read 25/10 with Success, so this is crawl priority on a new domain, not a site fault. Requested indexing for `/order/mobile-legends-malaysia`, `pubg-mobile-global`, `free-fire-malaysia`, `mobile-legends-indonesia` (top games by orders) and `/price-list`. `call-of-duty-mobile` was rejected: it serves `noindex` because the game is `is_active=0` since 2026-10-18 — correct.
- **Item 96, prod writes (founder go-ahead in session).** Evidence first: `backup_runs` shows "Backup Now → OpenWA" ran 2026-10-22 (run #55) and the nightly OpenWA runs #56/#58/#60 passed their restore tests. R2's zip headers showed 10 plaintext database archives (2026-09-20 … 2026-10-22) and every archive from 2026-10-23 (database) and all `openwa/` ones encrypted.
  - Deleted via tinker on `r2_backups`: only archives whose zip encryption flag is unset. Second run deleted 0. Remaining: `PekanGame/2026-10-23..25` and the 4 `openwa/` archives.
  - `rm -rf ~/OpenWA-v0.23.7 ~/openwa-backups` (1012 MB + 26 MB; disk 9.9G → 9.0G used). No `.env.bak*` left in `~/OpenWA`.
  - Appended `MESSAGE_RETENTION_DAYS=90` to `~/OpenWA/.env`; `resolveMessageRetentionDays()` reads 90 through dotenv. Oldest stored message is 2026-09-25, so the first prune deletes nothing until late December.
- **Item 96, closed.** The founder restarted OpenWA in Forge (08:13 UTC); both sessions came back `ready` and the log no longer says retention is disabled. Restore drill: the 2026-10-25 database archive (3,024,314 bytes, same as R2) was copied to the scratchpad and the founder ran `bsdtar -xOf … | tail` in their own terminal with the password from the password manager; it decrypted to a complete dump (the normal closing `SET` lines). The local copy was deleted afterwards.

## 2026-10-26 — Docs sync after releases #554 and #557

Docs only, no code. Checked against git and production (read-only) before
editing: `origin/main..origin/staging` is docs-only, no open PRs,
`/api/health` 200 with `scheduler: true` (the 25 Oct heartbeat cleared at
the 26 Oct 00:00 UTC run), `fpx` active since 2026-10-22 09:52 UTC,
`fpx_b2b1` and `duitnow_qr` off, no Sentry package or `SENTRY_*` env.

- PRD §16: items 131 and 132 closed (released via #554); group A now
  empty; item 114 moved to group D (built and released, waits on its launch
  gates); item 131's detail removed, item 81 and 114 details no longer
  name 131 as open; Epic-up is released, not on a feature branch.
- PRD §15: the Orders, Reports, Vouchers, Customer Analytics, Customer
  Login and Joki rows said "on `staging`" / "not released" for work that
  shipped in #554.
- PRD §14 and §11: health and payment state as of today; Joki points at
  group D.
- ADR-126 and ADR-127 Standing: "Accepted (design)" → "In force" (both are
  built), and ADR-127 no longer says IDR averaging is pending.

## 2026-10-26 — Gallery delete warning covers every image field; Joki rank icons

- **Found:** prod's Joki tier "Epic" pointed at a Gallery URL that 404s and
  has no `gallery_images` row: the image was deleted after it was set, with
  no warning. `GalleryImageController::references()` checked only Game,
  Hero Slide and Affiliate Branding. Three `ImageUrlInput` fields were
  missing: `joki_tiers.icon_url`, `affiliate_seo_settings.default_og_image`
  and `player_validator_profiles.public_image_url`. No customer impact (joki
  is off), but a brand's OG image or a checker image could break the same way.
- **Fix:** `references()` lists all three; test red first. Full suite green
  (3080).
- **Rank icons:** the founder's four PNGs (Epic, Mythic, Mythical Honor,
  Mythical Glory) were cut out (Mythic was a JPEG on black; Glory carried a
  leftover black patch), trimmed and centred on a 256×256 transparent
  canvas at one height, for the stepper's 84 px (64 px phone) and 44 px
  faded sizes. A local round-trip through `ImageIngestService` kept the
  alpha in the WebP. Legend has no icon yet; Epic needs re-uploading.

## 2026-10-26 — ADR-128: partner portal visual system (design)

Design only. The founder wants the partner portal (`reseller/`) redesigned:
next to the admin it looks plain, and its money pages (Wallet) read as
untrustworthy. Grilled Q1–Q15 in three rounds. Layer 1 is a frontend-only
reskin onto ADR-104's design system as a "PekanGame Partner" variant (nude
shell accent), with trust signals (company line, panel-configured support
contacts from `/api/catalog/branding`, "processed by CHIP"), tokens shared
by generation with a drift check (the `sync-theme-presets.mjs` pattern),
mockups in the design-system artifact first, then four PRs. Facts checked
for it: Reseller has no dashboard endpoint, a pending top-up is not
readable after creation (stays item 24), the SSM number exists only in the
About Us HTML, prod has 2 Resellers and 1 test Affiliate. PRD §16 item 133.

## 2026-10-26 — ADR-128 PR 1: shared tokens and the PekanGame Partner shell

- Mockups: 10 Partner Portal pages published in the design-system artifact
  (`pp-*` styles, a Partner Portal section). Writing them against the real
  API shapes removed what layer 1 can't show: the Reseller order list has
  no search and no client reference (`reference_number` is the platform's
  ORD-8 reference), no `paid_at` / `placed_via` on order detail, and wallet
  ledger rows carry no game or payment method. A draft line saying a
  withdrawal is "held" while pending was wrong: the ledger debit happens at
  admin approval (`Admin\WithdrawalController`), so the copy says earnings
  drop only on approval. No support hours, payout time or licence claim.
- Tokens: `admin/src/app/tokens.css` is the source, the portal imports a
  generated copy (`scripts/sync-shared-files.mjs`, CI drift check). Admin
  CSS output checked byte-for-byte against `staging`'s build: same bytes,
  one rule moved.
- Shell: grouped sidebar per role, nude account block, support box from the
  primary brand's public branding, top bar with breadcrumb and sign out,
  trust footer, restyled phone bar and More drawer, Figtree. Removed the
  TailAdmin rail, header, toggle and icon set.
- Verified: portal and admin `tsc`, `eslint`, `npm test`, `build` green;
  Playwright against the dev server with a mocked API at 1440 (both roles,
  light and dark), 768 and 390 (bar, More drawer): computed colours match
  the tokens, no horizontal overflow, no console errors.
- Found by the new portal colour-token test: `warning-300`, `warning-700`,
  `error-200` never had tokens, so those colours never rendered; fixed.

## 2026-10-26 — ADR-128 PR 2: dashboards, Orders and Order detail

- Built to the mockups on existing API data only (ADR-128 build note).
  Shared pieces in `components/ui.tsx` (badge, card head, copyable ID,
  balance hero, facts, pager, skeleton) and `components/orders/OrderTable`;
  the older `PageHeader`/`Panel`/`StatusTag` keep their API with the new
  look, so PR 3–4 screens already read better.
- Fixed in the shared tokens (affects the admin too): dark-mode
  `on-cyan` and `focus-ring` were missing, so every admin primary button in
  dark mode had white text on light cyan at 2.09:1. Now the artifact's
  values (8.1:1, ring 8.4:1).
- JetBrains Mono files added (from the design-system artifact), declared in
  `tokens.css`; both apps now render IDs in it (verified bundled in the
  admin build and loaded in the portal).
- Found while verifying at 768px: an `sr-only` table header escaped the
  scroll box and widened the page. Fixed with `relative` on the box.
- Verified: portal and admin `tsc`, `eslint`, `npm test`, `build`; Playwright
  with mocked API data at 1440 (both roles, light and dark), 768 and 390:
  no horizontal overflow, no console errors, mono font loaded.

## 2026-10-26 — ADR-128 PR 3: Wallet, Earnings and Withdrawal

- Built to the mockups. Shared pieces were added: `components/money/LedgerTable`, `MoneyForm` (amount field with quick amounts, steps card) and `shell/PaymentMark`. Every line of copy about money flow was checked against the code before it was written (ADR-128 PR 3 build note).
- The payment marks and the CHIP badge are now generated copies from the storefront, and CI checks them.
- Fixed: the portal's `proxy.ts` redirected `public/` files to `/login`, which broke the image optimizer.
- Found, not changed: the storefront checkout still says "BNM Compliant • 256-bit SSL" (`storefront/src/components/order/PaymentChannelPicker.tsx`). This is although #527 (2026-10-22) removed the storefront's Bank Negara Malaysia lines for CHIP's review. Raised with the founder.
- Verified: `tsc`, `eslint`, `npm test` and `build` all pass. Playwright with mocked data at 1440 (light and dark), 768 and 390: no overflow, no console errors, and the marks load.

## 2026-10-26 — ADR-128 PR 4: settings pages, Login and Set password

- Every remaining portal screen is moved to the semantic tokens. The ~700 legacy colour classes were mapped with a scripted one-to-one table, and the diff was confirmed class-only. A shared accessible `inputClass` replaces five copies that had `outline-none` and no focus style. Login and Set password share the mockup's `AuthLayout` (details in the ADR-128 PR 4 build note).
- The portal colour test now also blocks TailAdmin colour classes and `dark:` colour overrides.
- Verified: `tsc`, `eslint`, `npm test` and `build` pass. Playwright with mocked data covered Login (light, dark, 390), Profile (both roles), Subscription, Domains (light and dark), API keys (desktop and 390) and Storefront (desktop and 390): no overflow, no console errors.
- Layer 1 of ADR-128 is fully built. The founder reviews and tests it page by page before release.

## 2026-10-26 — Shared component kit decided; docs synced for the session

- #565 (PR 4) merged, so layer 1 of ADR-128 is on `staging` (#562–#565) and
  not released.
- Audit, at the founder's question (does the portal still carry legacy
  libraries, and does it follow the admin's library?):
  - TailAdmin is gone from the portal: icons, rail, `menu-item`, and the
    colour classes, which a test now blocks.
  - The portal uses no PrimeReact component. `primereact`, the provider
    and the PrimeUI licence check do nothing, and the screens run on a
    portal-only Tailwind kit. This departs from ADR-038 and was not raised
    before building.
  - The admin has no unused dependency (`react-is` is recharts' peer). It
    still carries 3,346 TailAdmin colour-class uses in 131 files (kept on
    purpose by ADR-104 decision 7) and the `menu-item` utility in
    `PanelSidebar`.
  - Its `components/ui` kit is mostly still TailAdmin-styled, and its
    primary `Button` measures 4.23:1 (white on `brand-500`).
- Founder decision: one shared kit, recorded in the ADR-128 2026-10-26
  addendum (admin kit onto the tokens first, then generated into the
  portal).
- Docs synced:
  - root and `reseller/` AGENTS.md: the token source, the sync script, the
    current kit gap;
  - PRD §14: what `staging` holds beyond prod;
  - PRD §15: a new "Partner portal UI" row, and the Gallery row's #560
    marked staging-only;
  - PRD §16: item 133 next steps, and new item 134 (the storefront's "BNM
    Compliant" line);
  - ADR-038 and ADR-128 Standing.

## 2026-10-26 — Shared component kit, PR 1: the admin kit onto the semantic tokens

**Why.** ADR-128 2026-10-26 addendum, step 1. The admin `components/ui` kit
(the PrimeReact wrappers) still carried TailAdmin/stock colours while the
portal's screens ran on the semantic tokens — two kits would drift. This PR
moves the admin kit onto the ADR-104 tokens so it can become the shared
source for PR 2.

**Shipped (branch `feature/adr-128-shared-kit-pr1`).**
- **Bridge repoint (`tokens.css`).** `--p-primary-color`/`-contrast`/`-hover`/
  `-active` now point at `cyan-600`/`on-cyan`/`cyan-700` (both `:root` and
  `.dark`) instead of the `brand-*` ramp. White on the primary button goes
  4.23:1 → 5.6:1 (dark: 8.1:1). `reseller/src/app/tokens.generated.css`
  regenerated via `scripts/sync-shared-files.mjs`.
- **Kit migration (10 files), one-to-one class swap, `dark:` twins removed:**
  - `button.tsx` — default/secondary/contrast + outlined/text variants onto
    `cyan-600`/`on-cyan`, `subtle`/`border`/`border-strong`,
    `ink`/`ink-muted`; the focus outline is now one `focus-ring` in the base.
  - `input.tsx` — `rounded-lg`→`rounded-md` (8px), `border-border-control`,
    `bg-surface`, `placeholder:text-ink-subtle`, the outline-based
    `focus-ring` (was `outline-hidden` + ring).
  - `select.tsx` / `switch.tsx` / `tabs.tsx` / `label.tsx` / `dialog.tsx` /
    `popover.tsx` — grey/brand/surface classes onto `ink`/`surface`/`subtle`/
    `border`/`cyan-*`.
  - `datatable.tsx` — `surface-*` → `surface`/`ink`/`subtle`, `bg-primary` →
    `bg-cyan-600`, sort badge `cyan-600`/`on-cyan`, and the frozen-edge
    shadows' hardcoded `rgba(226,232,240,1)`/`rgba(39,39,42,1)` →
    `var(--color-border)`.
  - `tag.tsx` — the last `contrast` severity (`surface-950`/`surface-0`) →
    `bg-ink text-surface`.

**Deferred, not built (PRD §16 item 135).** The button severities
`info`/`success`/`warn`/`danger`/`help` still use Tailwind's stock
`blue`/`green`/`amber`/`red`/`violet`. The design system defines status only
as `-surface`/`-ink` badge pairs, and those `-ink` tokens flip to a light
value in dark mode — a solid `bg-*-ink text-white` fails dark contrast. Needs
a founder decision (solid vs badge) before mapping. Left as-is with a code
comment in `button.tsx`.

**Verified.**
- `npm test` 19/19 (incl. `color-tokens.test.ts`), `npm run lint` clean,
  `npm run build` compiles 51/51 pages.
- Live browser (`getComputedStyle` on `/login`): primary button light
  `rgb(11,114,133)` = `#0b7285` with white (5.6:1); dark `rgb(76,195,212)`
  with `rgb(11,31,36)` (8.1:1). No console errors (the two 401s are
  `resumeSession()` on a fresh browser).
- `RichTextEditor` (the one hand-rolled primitive, ADR-038) is untouched —
  it is not part of the shared-kit source and its `gray-*`/`brand-*` classes
  are already retinted.

**Not live.** Ships with PR 2 (the kit generated into the portal) as item 133,
before the founder's page-by-page review.

## 2026-10-26 — Shared component kit, PR 2: the kit generated into the portal, hand-rolled kit deleted

**Why.** ADR-128 2026-10-26 addendum, step 2. With the admin kit on the
semantic tokens (PR 1), this makes the portal use the same kit instead of
its hand-rolled `ui.tsx`, so the two apps can't drift.

**Shipped (branch `feature/adr-128-shared-kit-pr2`).**
- **Severity colours resolved** (PRD §16 item 135, was deferred in PR 1):
  the Button severities `info`/`success`/`warn`/`danger`/`help` now use the
  design system's `-surface`/`-ink` badge pairs (soft fill + a border that
  intensifies on hover), never a solid `bg-*-ink` (which fails dark contrast)
  and never a `dark:` colour override. This unblocked generation, because the
  portal's `color-tokens.test.ts` rejects `dark:` colour overrides.
- **Touch targets** (ADR-112): Button `small`/`normal` and Input height are
  now `min-h-11 sm:min-h-9` (44px phone / 36px desktop), and the Input gained
  `focus:border-transparent`. A new `Textarea` (native `<textarea>` on
  `inputVariants`) completes the form primitives.
- **Generation.** `scripts/sync-shared-files.mjs` now also copies the 11
  PrimeReact wrappers plus `lib/utils.ts` (and `textarea.tsx`) into
  `reseller/src/components/ui/`; the portal gained the kit's deps
  (`@primeicons/react`, `class-variance-authority`, `tailwind-merge`).
- **Portal swap.** The hand-rolled `reseller/src/components/ui.tsx` is
  deleted. Its composites moved to `reseller/src/components/composites.tsx`
  (`PageHeader`, `Panel`, `BalanceHero`, `Facts`, `CopyId`, `Pager` …), with
  `Badge`/`StatusTag` as thin wrappers on the shared `Tag` (keeping the
  portal's `tone` vocabulary and its always-on dot) and `Pager` on the shared
  `Button`. 21 files migrated: `<button className={buttonClass(…)}>` →
  `<Button>`, `<input/textarea className={inputClass}>` → `<Input>`/`<Textarea>`.
  `buttonClass` stays only for `<Link>`/`<a>` (a navigation link must keep its
  `<a>` semantics), imported from `composites.tsx`. `Toggle` in the storefront
  `shared.tsx` is now the shared `Switch`.

**Verified.**
- Portal: `npm test` 8/8, lint clean, `npm run build` 21/21. The generated
  kit passes the portal's stricter `color-tokens.test.ts` (no `dark:` colour
  override, no TailAdmin class).
- Admin: `npm test` 19/19, `npm run build` 51/51 (the severity/sizing changes
  re-verified).
- Live browser (`getComputedStyle`): portal `/login` "Sign in" button
  `rgb(11,114,133)` = `#0b7285` cyan-600, inputs white 8px radius, no console
  errors. The admin's severity buttons (soft fill) and the portal's full
  page-by-page look are the founder's review (next step in item 133).

**Not live.** Ships with the rest of item 133 (the founder's page-by-page
review of layer 1, then a release).

## 2026-10-27 — Dependency advisories: targeted upgrades (next, zod, livewire, docs-site)

**Why.** The weekly `dependency-audit` (2026-10-17) went red on newly
published advisories. The one that matters is a **critical `next` RCE**
(unauthenticated, via the Image Optimization API with SVG — plus a DoS in
Server Actions and an RCE in `use cache`), affecting all three Next.js apps.

**Shipped (branch `fix/dependency-advisories`), targeted, no `--force`/`--with-dependencies`:**
- `next` `^16.3.8` → `^16.4.3` (admin, storefront, reseller). Patch bump
  within 16.x; 16.4.0 was the vulnerable floor.
- `zod` `^4.4.3` → `^4.6.7` (storefront, direct runtime dep per ADR-044).
- `livewire/livewire` v4.4.2 → v4.4.8 (backend; transitive via
  `laravel/pulse`, the Pulse dashboard). Medium: `#[Authorize]` authorization
  bypass. No Livewire use in `app/`/`routes/` — Pulse's own UI only.
- `zod` dev-only (admin, reseller, docs-site, via the `eslint-config-next`
  → `eslint-plugin-react-hooks` → `zod-validation-error` chain): bumped
  transitively by `npm audit fix` (non-force). Not shipped; the CI's
  `--omit=dev` never flagged it.
- docs-site (build-time only, static SSG): `undici` 8.10.2→8.12.3,
  `vite` 8.3.0→8.3.5, `fast-uri` 3.1.8→3.1.9,
  `postcss-selector-parser` 6.1.4→6.1.5, `zod` 4.6.1→4.6.7 via `npm audit fix`
  (non-force). `astro` stays: the one remaining advisory is **low** (CSS
  injection in View Transitions) and needs `--force` to reach 7.4.2, which is
  outside the stated range — the same `--force` the policy says not to use.

**Verified.**
- `composer audit` clean; `npm audit` = 0 in admin/storefront/reseller;
  docs-site `npm audit --omit=dev --audit-level=high` passes (only the `low`
  astro advisory remains, below the CI threshold).
- admin build 51/51 + test 19/19; storefront build 14/14; reseller build
  21/21 + test 8/8; docs-site build complete; backend `php artisan test`
  3080/3080.

**Not live.** Ships with the portal redesign + shared kit in the next
`staging`→`main` release (one regression window, see item 133).

## 2026-10-27 — Pre-release review of the portal redesign + shared kit; release #571 live

**Context.** The shared-kit PRs (#567, #568) and the dependency fix (#570)
were built in OpenCode, not Claude Code, as the founder's test of another
agent. Claude reviewed them, merged #570, then took over the page-by-page
review and the release at the founder's request.

**Review of the OpenCode PRs.** The process matched AGENTS.md: branches off
`staging`, stacked PRs, granular commits, docs updated, tests and builds run,
and the founder was asked before the status-button colour was chosen. Two
gaps, closed in this session's docs PR:
- The founder's choice of soft over solid status buttons was recorded as
  "forced by" the colour test, with no ADR addendum. Now ADR-128 2026-10-27
  addendum; PRD item 135 reworded.
- No admin screenshot of the restyled status buttons or the 30px `small`
  size before "done". Covered by the review below.

**Pre-release review (local dev, real dev-DB data, Playwright).**
- The founder's `./scripts/dev.sh` was still running Next 16.3.8 from
  2026-10-25 after `npm install` had moved `node_modules` to 16.4.3, so every
  admin and portal page answered 500. Restarted with the founder's go-ahead.
  Gotcha: Next 16.4.3's dev server rewrites the banner heading in
  `admin/`, `storefront/` and `reseller/` `AGENTS.md` (`#` → `##`);
  committed so the tree stays clean.
- Portal: Reseller (Dashboard, Orders, Wallet, API keys, Subscription,
  Profile) and Affiliate (Dashboard, Orders, Earnings, Withdrawal,
  Storefront, Domains, Profile), light and dark, 1440px and 390px, plus
  Login and Set password: 58 screenshots. No horizontal overflow, no error
  state, no console error except a 403 the Reseller gets on the
  Affiliate-only `/subscription`.
- Admin: 12 pages (Dashboard, Orders, Withdrawals, Vouchers, Games,
  Resellers, Affiliates, Gallery, Accounting Summary, Users, Middleware
  Suppliers, Price Sync), light and dark. Console errors were local-only
  (CSP for the Herd image host and Reverb's `ws://localhost:8080`).
- Measured (`getComputedStyle`, Vouchers page): primary button 5.59:1 light,
  8.14:1 dark; danger "Revoke" 5.70:1 light, 7.66:1 dark.
- A full-page screenshot draws the portal's fixed phone bottom bar
  mid-page and cuts the sidebar at the viewport height. That is how the
  screenshot works, not a layout bug.

**Release #571 (`staging`→`main`, requested by the founder).** Merged as
`f4177bc` at 2026-10-26 17:06 UTC; ships #560–#570. CI and `deploy` green.
Verified read-only:
- the box runs release `81251053` at `f4177bc`;
- `livewire/livewire` v4.4.8 in the deployed lock;
- Horizon running, `/api/health` 200;
- all four Vercel production builds succeeded;
- `reseller.pekangame.space/login` serves the "PekanGame Partner" shell.

No migrations, no env changes. PRD item 133 closed.

## 2026-10-27 — ADR-129: action feedback (toasts) — PR 1 (wrapper + provider + three screens)

**Why.** Neither frontend gave consistent feedback after an action — success
and error were inline page-top `<p>` state (`setError` / `setNotice` /
"Saved.") inside each modal or page (112 admin files, 20 portal files). An
admin couldn't tell whether an action ran, was running, or failed. ADR-129
(design, PR #573) decides: one PrimeReact headless toaster, one shared
wrapper, a toast-vs-inline policy, promise-driven success, sticky money
errors.

**What shipped (PR #574, merged to `staging` 2026-10-27; not yet on `main`).**
- `admin/src/components/ui/toast.tsx`: `ToastProvider` + `notify`
  (`success` / `info` / `warn` / `error` / `stickyError` / `promise`) +
  `useToast()`, on the semantic `-surface` / `-ink` tokens only (no `dark:`
  overrides — passes both `color-tokens.test.ts`). Built on
  `@primereact/headless/toaster` (`toast()` manager + `useToaster()`), which
  share one manager — no bespoke store.
- Added `"toast"` to the `KIT` list in `scripts/sync-shared-files.mjs`, so the
  portal's copy is generated, never hand-edited.
- Mounted `ToastProvider` in both root layouts.
- Three screens: admin Withdrawals (approve/reject/complete → success toast +
  `stickyError`), admin Orders (refund-to-wallet, mark-delivered,
  confirm-failed, voucher-issued, export, retry-queue error → toast; the async
  resend/retry "waiting for outcome" line stays inline), portal Withdrawal
  (success toast; form errors stay inline).
- E2E `admin-mark-delivered` and `admin-issue-voucher` now assert the split
  toast title.

**Gotchas.**
- Playwright caught two specs still asserting the old inline strings
  (mark-delivered "— ledger profit credited.", issue-voucher trailing period);
  both updated in the same work.
- `@primereact/headless` was a phantom dependency (transitive via
  `primereact`); declared it explicitly in both `package.json` + lockfiles.

**Follow-up (review notes, separate PR).** The #574 review raised five notes —
reduced-motion and focus-on-dismiss were promised but not yet done; the
`limit: 4` prop and `data-enter-from` classes had no effect; Withdrawals
refreshed only on success; and there was no build-log entry. All five are
addressed in the follow-up sweep PRs, which stay open together against
`staging` for one review.

## 2026-10-27 — ADR-129 action feedback (toasts): full rollout (#575–#580)

**Why.** After the PR-1 wrapper (#574), the remaining screens still showed
action outcomes as inline `setError`/`setNotice`/"Saved." state — not silent,
but not toasts. This closes ADR-129 (§16 item 136): every action outcome is a
toast; field validation, load/empty states, persistent warnings and the
order-modals' confirmations stay inline on purpose.

**What shipped (#575–#580, released to `main` via #581).**
- #575: the five review notes on #574 — `@primereact/headless` declared,
  focus-on-dismiss returns to the region, entrance motion dropped
  (a mount-flip sets state in an effect, which the lint forbids), the
  visible `limit` honoured by slicing, Withdrawals refreshes after a failed
  action too, and this file's first entry.
- #576: Vouchers, Affiliates, Resellers, Admin users (the shared `guard()`
  takes a success message).
- #577: Middleware panel (suppliers, payment methods, gateways, product
  manager, sandbox, validators). A follow-up fix read the response body, not
  just the exception: a failed payment-method test answers 200 with the
  failure in `last_test_result`, and a breaker-open supplier probe is not a
  credential failure (ADR-069 stress-test Q3).
- #578: Games/SEO/Gallery/Hero slides/Reviews/Blacklist — replaces the
  inline "Saved." flag on the SEO save screens.
- #579: the money-critical flows — manual-order finalize, membership payment,
  supplier transfer, envelope posting/void/month close, transfer correction,
  portal wallet top-up. Failures are sticky (`duration: Infinity`).
- #580: the remaining money actions (AffiliateDetailModal tier charge/
  reactivate/assign, Settlements update, TierCard price, Joki payout share)
  and the non-money config (Joki rank model/routes/settings, admin Settings
  sections, Reseller API-keys/webhook/portal-users, portal api-keys/webhook/
  profile/domains).

**Money-safety discipline.** A success toast fires only after the promise
resolves (and, where the endpoint returns an outcome in its body, only when
that outcome says so); money-action failures are sticky so they can't vanish.
No backend, ledger or money-logic change anywhere in the rollout.

**Gotchas.**
- The review caught one more "200 with a failure in the body": the affiliate
  tier-fee charge answers 200 when earnings are short (no debit, the cycle
  goes to grace) or the cycle is already paid, so "Tier fee charged" could
  show for a charge that never happened. The success toast now requires an
  advanced `next_charge_at`; otherwise it warns "Fee not charged". The
  payment-method test (#577) is the same class of fix.
- The `guard`/`run`/`save` helpers across screens were each extended with an
  optional success message instead of a bespoke toast store, so the library
  stays swappable in one file.
- Removed several now-unused `error`/`notice`/`saved` states; lint caught the
  leftover `JOKI_ERROR`/`JOKI_NOTICE` imports and `setError` assignments.
- The portal copy is generated (`scripts/sync-shared-files.mjs`), never
  hand-edited; CI's `theme-preset-drift` guards it.
