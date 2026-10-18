<?php

namespace App\Console;

use App\Jobs\SyncSupplierPricesJob;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Support\MalaysiaTime;
use Illuminate\Console\Scheduling\Schedule;

/**
 * ADR-122: the one definition of every scheduled task. `routes/console.php`
 * registers it on the real scheduler; `SchedulerLiveness` builds a
 * throwaway `Schedule` from it to judge each task's heartbeat, because an
 * HTTP request never loads `routes/console.php`.
 *
 * Every task needs a unique `name()`, a tier below, and
 * `withoutOverlapping(self::OVERLAP_EXPIRY_MINUTES)` — TaskScheduleTest
 * enforces all three.
 */
class TaskSchedule
{
    /**
     * Laravel's default lock expiry is 1440 minutes: a process killed
     * mid-task (reboot, OOM) would skip that task for a day. 30 can't cause
     * overlap for an hourly or daily task; the 15-minute reconciliations
     * are overlap-safe (each outcome is applied under lockForUpdate).
     */
    public const OVERLAP_EXPIRY_MINUTES = 30;

    /** Stale → /api/health 503 → UptimeRobot alert. */
    public const CRITICAL = [
        'price-sync',
        'payment-reconciliation',
        'delivery-reconciliation',
        'wallet-topup-reconciliation',
        'database-backup',
        'database-backup-cleanup',
        'membership-cycle-reset',
        'membership-payment-reconciliation',
        'membership-renewal-reminders',
        'affiliate-tier-fee-charge',
        'supplier-balance-refresh',
        'order-credential-wipe',
    ];

    /** Stale → admin Dashboard only. */
    public const ROUTINE = [
        'player-validation-pruning',
        'supplier-request-log-pruning',
        'report-assistant-audit-log-pruning',
        'credential-reveal-pruning',
        'reseller-whatsapp-pending-link-pruning',
        'reseller-bot-command-log-pruning',
        'stale-cache-pruning',
        'affiliate-domain-status-sync',
        'horizon-snapshot',
        'price-sync-staleness-check',
    ];

    public static function isCritical(string $name): bool
    {
        return in_array($name, self::CRITICAL, true);
    }

    public static function define(Schedule $schedule): void
    {
        $overlap = self::OVERLAP_EXPIRY_MINUTES;

        // ADR-015 decision #6: writes its own PriceSyncRun row
        // (triggered_by='system') the same way the manual "Sync All Prices Now"
        // trigger does, so Sync History (ADR-016) shows both uniformly. Active
        // in production since the Forge cutover (2026-09-02).
        //
        // Skips creating a run entirely when there is no syncable supplier — an
        // active Supplier row with a non-empty api_config. Pre-launch (and any
        // time every supplier is paused/unconfigured) there is nothing to sync,
        // and a run per tick would just be Sync-History noise. The manual
        // trigger (PriceSyncController::store) is deliberately not gated this
        // way — the founder can still fire one to prove the pipeline.
        $priceSyncIntervalMinutes = config('packages.price_sync_interval_minutes');

        $schedule->call(function () {
            $hasSyncableSupplier = Supplier::query()
                ->where('is_active', true)
                ->get()
                ->contains(fn (Supplier $supplier) => filled($supplier->api_config));

            if (! $hasSyncableSupplier) {
                return;
            }

            $run = PriceSyncRun::query()->create(['status' => 'queued', 'triggered_by' => 'system']);
            SyncSupplierPricesJob::dispatch($run);
        })->cron("*/{$priceSyncIntervalMinutes} * * * *")
            ->name('price-sync')
            ->withoutOverlapping($overlap);

        // ADR-015 2026-10-17 addendum (PRD §16 item 106) — emails admins when a
        // supplier's prices stop syncing. Inline in the scheduler, so it still
        // fires when the queue that runs price-sync is down.
        $schedule->command('app:check-price-sync-staleness')
            ->cron('*/15 * * * *')
            ->name('price-sync-staleness-check')
            ->withoutOverlapping($overlap);

        // ADR-021 (PAY-3) — catches orders whose payment webhook never
        // arrived — see ReconcilePendingPaymentsCommand's own docblock.
        $schedule->command('app:reconcile-pending-payments')
            ->cron('*/15 * * * *')
            ->name('payment-reconciliation')
            ->withoutOverlapping($overlap);

        // ADR-026 (ORD-10) — delivery-side counterpart to the payment
        // reconciliation above. Catches orders whose supplier order-creation
        // outcome is ambiguous — see ReconcilePendingDeliveriesCommand.
        $schedule->command('app:reconcile-pending-deliveries')
            ->cron('*/15 * * * *')
            ->name('delivery-reconciliation')
            ->withoutOverlapping($overlap);

        // ADR-073 decision 3(a) / PR-G planning addendum decision 9 — same
        // 15-min cadence as payment-reconciliation above (not membership's own
        // daily one): a WalletTopupAttempt's whole window is only 30 minutes
        // (decision 8), so a coarser cadence would risk recovering a genuinely
        // paid top-up hours late. See ReconcilePendingWalletTopupsCommand's own
        // docblock.
        $schedule->command('app:reconcile-pending-wallet-topups')
            ->cron('*/15 * * * *')
            ->name('wallet-topup-reconciliation')
            ->withoutOverlapping($overlap);

        // ADR-021 — prunes player_validations PII past its retention window —
        // see PrunePlayerValidationsCommand's own docblock.
        $schedule->command('app:prune-player-validations')
            ->daily()
            ->name('player-validation-pruning')
            ->withoutOverlapping($overlap);

        // ADR-051 — prunes supplier_request_logs past its (split) retention
        // window — see PruneSupplierRequestLogsCommand's own docblock.
        $schedule->command('app:prune-supplier-request-logs')
            ->daily()
            ->name('supplier-request-log-pruning')
            ->withoutOverlapping($overlap);

        // ADR-087 decision 8 — prunes report_assistant_audit_logs past its
        // 90-day retention window — see PruneReportAssistantAuditLogsCommand.
        $schedule->command('app:prune-report-assistant-audit-logs')
            ->daily()
            ->name('report-assistant-audit-log-pruning')
            ->withoutOverlapping($overlap);

        // ADR-124 decision 7 (2026-10-18 addendum) — the one place a
        // customer's game login is wiped; critical, because a stale sweep
        // keeps passwords past the promise made at checkout.
        $schedule->command('app:wipe-order-credentials')
            ->cron('*/15 * * * *')
            ->name('order-credential-wipe')
            ->withoutOverlapping($overlap);

        // ADR-124 decision 11 — credential_reveals kept one year.
        $schedule->command('app:prune-credential-reveals')
            ->daily()
            ->name('credential-reveal-pruning')
            ->withoutOverlapping($overlap);

        // PR-F build addendum (ADR-075) — prunes reseller_whatsapp_pending_links
        // past its 24h TTL and reseller_bot_command_logs past its 7-day
        // retention — see each command's own docblock.
        $schedule->command('app:prune-reseller-whatsapp-pending-links')
            ->hourly()
            ->name('reseller-whatsapp-pending-link-pruning')
            ->withoutOverlapping($overlap);

        $schedule->command('app:prune-reseller-bot-command-logs')
            ->daily()
            ->name('reseller-bot-command-log-pruning')
            ->withoutOverlapping($overlap);

        // ADR-077 decision 3 — the `database` cache store has no lazy-sweep for
        // keys that expire without being read again. No-op on the redis-primary
        // production path (ADR-077 decision 1); keeps the `cache` table from
        // bloating in any environment left on CACHE_STORE=database.
        $schedule->command('app:prune-stale-cache')
            ->daily()
            ->name('stale-cache-pruning')
            ->withoutOverlapping($overlap);

        // ADR-039 decision 2 — `--triggered-by=system` distinguishes this from
        // the manual "Backup Now" admin action, both of which go through the
        // same RunBackupCommand (dump -> locate archive -> decision 8's restore
        // test -> one BackupRun row). 02:07, not 02:00 (item 97): off the minute
        // price sync and the */15 jobs start on, since the restore test also
        // writes to the same database server.
        $schedule->command('app:run-backup', ['--triggered-by' => 'system'])
            ->dailyAt('02:07')
            ->name('database-backup')
            ->withoutOverlapping($overlap);

        // ADR-039 decision 6 — retention/auto-thinning (7 daily + 4 weekly + 6
        // monthly, config/backup.php's `cleanup.default_strategy`). Scheduled
        // an hour after the backup above (not chained — Laravel's scheduler has
        // no native "run after this other named task" dependency) so a fresh
        // run always exists on disk before old ones are thinned.
        $schedule->command('backup:clean')
            ->dailyAt('03:00')
            ->name('database-backup-cleanup')
            ->withoutOverlapping($overlap);

        // ADR-027 Phase 6 — refills quota_remaining_sen for any membership whose
        // rolling 30-day cycle has elapsed — see ResetMembershipCyclesCommand.
        $schedule->command('app:reset-membership-cycles')
            ->daily()
            ->name('membership-cycle-reset')
            ->withoutOverlapping($overlap);

        // ADR-068 decision 10 — catches a self-serve membership subscription
        // whose CHIP webhook was never delivered; completes it from the
        // gateway's own status, or expires a genuinely abandoned attempt. See
        // ReconcilePendingMembershipPaymentsCommand's own docblock.
        $schedule->command('app:reconcile-pending-membership-payments')
            ->daily()
            ->name('membership-payment-reconciliation')
            ->withoutOverlapping($overlap);

        // ADR-068 2026-10-13 addendum — renewal-reminder emails (H-3 and H+1).
        // 10:00 Malaysia time, after the 08:00 MYT cycle reset; the selection
        // reads expires_at, not status, so the order between them doesn't matter.
        $schedule->command('app:send-membership-reminders')
            ->dailyAt('10:00')
            ->timezone(MalaysiaTime::TIMEZONE)
            ->name('membership-renewal-reminders')
            ->withoutOverlapping($overlap);

        // ADR-056 — collects the monthly affiliate wholesale-tier subscription
        // fee from each affiliate's earnings balance and drives active -> grace
        // (3 days) -> lapsed on an unpaid cycle — see
        // ChargeAffiliateTierFeesCommand's own docblock.
        $schedule->command('app:charge-affiliate-tier-fees')
            ->daily()
            ->name('affiliate-tier-fee-charge')
            ->withoutOverlapping($overlap);

        // ADR-069 decision 12 — Supplier.balance is otherwise only refreshed
        // by an admin clicking "Refresh Balance", so the Dashboard Health
        // figure can be days stale. Refreshes every active configured supplier
        // once a day and logs a warning when a balance drops below its own
        // api_config['low_balance_threshold']. See RefreshSupplierBalancesCommand.
        $schedule->command('app:refresh-supplier-balances')
            ->daily()
            ->name('supplier-balance-refresh')
            ->withoutOverlapping($overlap);

        // ADR-060 (2026-09-06 "domain lifecycle" addendum, section H) — polls
        // the hosting provider for every pending/failed affiliate custom domain,
        // tears down domains stuck pending past the 14-day TTL, and sends day-3 /
        // day-7 DNS reminders. The portal "Check now" button is the on-demand
        // path; this is the backstop. See SyncAffiliateDomainStatusCommand.
        $schedule->command('app:sync-affiliate-domain-status')
            ->daily()
            ->name('affiliate-domain-status-sync')
            ->withoutOverlapping($overlap);

        // ADR-048 addendum (2026-09-29, audit Wave 4 Low): Horizon's metrics/graphs
        // stay empty without this — snapshots are never taken on their own.
        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->name('horizon-snapshot')
            ->withoutOverlapping($overlap);
    }
}
