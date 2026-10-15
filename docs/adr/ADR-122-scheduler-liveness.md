# ADR-122: Scheduler liveness — every scheduled task records a heartbeat, and a stale critical task fails `/api/health`

> **Standing (2026-10-15):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-10-15. Grilled with the founder in three rounds (`/mattpocock-skills:grilling`), then stress-tested against Laravel v13.21.1's own scheduler code and production (PRD §16 item 98).

**Context:** Production runs the Laravel scheduler from one line in the Forge box's `/etc/crontab` (`* * * * * forge php8.4 …/current/backend/artisan schedule:run > ~/.forge/scheduled-2143348.log`). Its log is overwritten every minute and read by nobody. UptimeRobot polls `/api/health`, which checks DB, Redis and Horizon ([ADR-014](./ADR-014-24-7-burst-resilience-baseline.md), its 2026-09-29 Horizon check), but nothing checks the scheduler. When scheduled work stops, payment/delivery/wallet reconciliation, price sync, membership cycle resets, affiliate tier fees and the nightly backup all stop with no alert. The ways it stops, all silent today:

- **A.** Cron itself stops: a scheduler paused in Forge (already happened on the old box, ADR-114), a lost crontab entry, or a new box without one.
- **B.** `schedule:run` crashes at boot while HTTP still serves.
- **C.** A stuck `withoutOverlapping` lock. Laravel's default expiry is 1440 minutes, so a process killed mid-task (reboot, OOM) skips that task for 24 hours: 96 runs of `payment-reconciliation`, or the next day's run of a daily task.
- **D.** One task throws on every run, visible only in `laravel.log`.
- **E.** Seven tasks were `Schedule::call(fn () => Artisan::call(...))`. `CallbackEvent::execute()` treats only a `false` return as failure, so a command returning `FAILURE` (int `1`) counted as success. Latent: no such command returns `FAILURE` today.

An alert sent from inside the app (the `BackupFailureAlerter`/Plunk path) can't catch A or B, because the thing that would send it is what died. That is the same reason Horizon went into `/api/health` on 2026-09-29.

**Decision:**

1. **The detector is outside the failure domain:** a stale critical task makes `/api/health` return 503 with `checks.scheduler: false`, so the existing UptimeRobot monitor alerts. No push/heartbeat service, no in-app alert.
2. **Per-task heartbeat, not one global tick.** A listener on Laravel's `ScheduledTaskFinished` / `ScheduledTaskFailed` events writes one `scheduled_task_heartbeats` row per task name: `last_ok_at`, `last_runtime_seconds`, `last_failed_at`, `last_error`. Covers A–D; a global tick would only cover A–B.
   - **OK means `exitCode === 0` and not `skippedBecauseOverlapping`.** Stress-test finding: when `Event::run()` skips for overlap it returns early, but `ScheduleRunCommand` still dispatches `ScheduledTaskFinished` with a `null` exit code, and `null != 0` is false. A naive listener would record success every 15 minutes for a task held off by a stuck lock, hiding case C.
   - **The listener never throws.** `ScheduledTaskFailed` is dispatched from inside `ScheduleRunCommand`'s `catch` block, so a listener exception there escapes and aborts every other task due that minute. A write failure is logged and dropped.
3. **Stored in MySQL, not the Redis cache.** The cache DB runs `volatile-lru` (ADR-077): a TTL'd key can be evicted (false alarm), and a key without one breaks ADR-077's "every cache key has a TTL". A Redis flush would turn every task red.
4. **Staleness rule**, computed from each task's own cron expression and timezone (no hand-kept schedule table):
   - A due time counts once its grace has passed: **30 minutes for a task that runs more than once a day, 60 minutes for a daily task.** The nine daily tasks run one after another in one 00:00 process, so a slow one delays the rest; runtimes aren't recorded anywhere yet, so 60 is the safe start and `last_runtime_seconds` is the data to tune it.
   - **Sub-daily tasks may miss one due run; a daily task is stale after one missed run.** One transient failure of a 15-minute task doesn't page; one missed membership cycle reset does.
   - The baseline is `last_ok_at`, or the row's `created_at` before the first success.
5. **Two tiers, declared in one place.** `CRITICAL` tasks (money, customers, backups) fail `/api/health`. `ROUTINE` tasks (pruning, `horizon:snapshot`, domain sync) only show on the admin Dashboard.
6. **One source of truth for the schedule: `App\Console\TaskSchedule`.** Stress-test finding: `routes/console.php` is only `require`d by the console kernel (`Kernel.php` `commands()`), so an HTTP request sees an empty `Schedule`. Reading only the heartbeat rows would leave a box whose cron never ran green (case A). `routes/console.php` calls `TaskSchedule::define(app(Schedule::class))`; the health check builds a throwaway `new Schedule` and calls the same method. Closures are defined, never run.
7. **Invariant test:** every scheduled task has a unique name, sits in exactly one of `CRITICAL`/`ROUTINE`, and uses `withoutOverlapping(30)`. A new task without a tier fails CI, so the tier is always a conscious choice.
8. **A task with no row is seeded the first time the check sees it** (`created_at = now`, `last_ok_at` null), then judged by rule 4. A task that never runs goes red one interval plus grace after deploy. Rows for tasks no longer in the schedule are ignored.
9. **Public vs admin.** `/api/health` carries only the boolean (it's unauthenticated, and task names describe internal operations). The admin Dashboard Health panel lists each task's tier, last OK, last failure, last error and runtime.
10. **Two silent causes fixed at the root, same change:**
    - The seven `Schedule::call(fn () => Artisan::call(...))` tasks become `Schedule::command(...)`, so a failing exit code is a failure (case E).
    - **Every task uses `withoutOverlapping(30)`** instead of the 1440-minute default (case C prevention). A short expiry can't cause overlap for an hourly or daily task (it isn't due again within 30 minutes). For the 15-minute reconciliations a run over 30 minutes could overlap the next; checked safe: each applies its outcome under `lockForUpdate` with a state re-read (`OrderPaymentOutcomeService`, `ReconcilePendingDeliveriesCommand`, `ResellerWalletService`), the same race they already have with webhooks.

**Out of scope:** whether a dispatched job succeeded (backup and price sync already record their own outcome in `BackupRun` / `PriceSyncRun`, and a backup failure already alerts); an immediate Plunk alert on task failure (the 30/60-minute grace through UptimeRobot is fast enough); `onOneServer` for a future second app server (heartbeats in a shared DB stay correct, but duplicate runs are a separate problem).

**Rationale:** The detector has to sit outside what it watches, and the project already has one external watcher with a tested alert path, so the scheduler joins Horizon on `/api/health`. Per-task heartbeats are the only shape that sees the most likely failure on this box (a lock left by a killed process). Both stress-test findings (overlap-skip reported as success, HTTP not seeing the schedule) would have shipped a monitor that stays green in exactly the cases it exists for.

**Consequence to track:**
- After release: `scheduled_task_heartbeats` fills within 15 minutes, `/api/health` stays 200, and after the first 00:00 run every daily task has a row. Read the recorded runtimes after a week and revisit the 60-minute daily grace.
- Adding a scheduled task now means adding its name to `CRITICAL` or `ROUTINE` in `TaskSchedule` (CI enforces it).
