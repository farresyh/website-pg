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

**Left (PRD §16 item 119):** verify the next release is a single "via Custom"
deployment after the CI `deploy` job; delete the orphaned old server from
Forge; delete the unused `DIGITALOCEAN_ACCESS_TOKEN` repo secret. ADR-114 got
a 2026-10-17 addendum with the box-move checklist; the `ci.yml` header
comment now names the setting "Push to deploy".
