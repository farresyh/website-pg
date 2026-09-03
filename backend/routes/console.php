<?php

use App\Jobs\SyncSupplierPricesJob;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

Schedule::call(function () {
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
    ->withoutOverlapping();

// ADR-021 (PAY-3) — same inert-until-real-cron pattern as Price Sync
// above: activates for free once a real OS cron exists on a deployed
// host, a no-op locally today. Catches orders whose Xendit webhook
// never arrived — see ReconcilePendingPaymentsCommand's own docblock.
Schedule::call(fn () => Artisan::call('app:reconcile-pending-payments'))
    ->cron('*/15 * * * *')
    ->name('payment-reconciliation')
    ->withoutOverlapping();

// ADR-026 (ORD-10) — delivery-side counterpart to the payment
// reconciliation above, same inert-until-real-cron pattern. Catches
// orders whose Gamevion order-creation outcome is ambiguous — see
// ReconcilePendingDeliveriesCommand's own docblock.
Schedule::call(fn () => Artisan::call('app:reconcile-pending-deliveries'))
    ->cron('*/15 * * * *')
    ->name('delivery-reconciliation')
    ->withoutOverlapping();

// ADR-021 — same inert-until-real-cron pattern as above. Prunes
// player_validations PII past its retention window — see
// PrunePlayerValidationsCommand's own docblock.
Schedule::call(fn () => Artisan::call('app:prune-player-validations'))
    ->daily()
    ->name('player-validation-pruning')
    ->withoutOverlapping();

// ADR-051 — same inert-until-real-cron pattern as above. Prunes
// supplier_request_logs past its (split) retention window — see
// PruneSupplierRequestLogsCommand's own docblock.
Schedule::call(fn () => Artisan::call('app:prune-supplier-request-logs'))
    ->daily()
    ->name('supplier-request-log-pruning')
    ->withoutOverlapping();

// ADR-039 decision 2 — same inert-until-real-cron pattern as above.
// `--triggered-by=system` distinguishes this from the manual "Backup
// Now" admin action, both of which go through the same
// RunBackupCommand (dump -> locate archive -> decision 8's restore
// test -> one BackupRun row).
Schedule::command('app:run-backup', ['--triggered-by' => 'system'])
    ->dailyAt('02:00')
    ->name('database-backup')
    ->withoutOverlapping();

// ADR-039 decision 6 — retention/auto-thinning (7 daily + 4 weekly + 6
// monthly, config/backup.php's `cleanup.default_strategy`). Scheduled
// an hour after the backup above (not chained — Laravel's scheduler has
// no native "run after this other named task" dependency) so a fresh
// run always exists on disk before old ones are thinned.
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->name('database-backup-cleanup')
    ->withoutOverlapping();

// ADR-027 Phase 6 — same inert-until-real-cron pattern as above.
// Refills quota_remaining_sen for any membership whose rolling 30-day
// cycle has elapsed — see ResetMembershipCyclesCommand's own docblock.
Schedule::command('app:reset-membership-cycles')
    ->daily()
    ->name('membership-cycle-reset')
    ->withoutOverlapping();

// ADR-068 decision 10 — catches a self-serve membership subscription
// whose CHIP webhook was never delivered; completes it from the
// gateway's own status, or expires a genuinely abandoned attempt. See
// ReconcilePendingMembershipPaymentsCommand's own docblock.
Schedule::command('app:reconcile-pending-membership-payments')
    ->daily()
    ->name('membership-payment-reconciliation')
    ->withoutOverlapping();

// ADR-056 — same inert-until-real-cron pattern as above. Collects the
// monthly reseller wholesale-tier subscription fee from each reseller's
// earnings balance and drives active -> grace (3 days) -> lapsed on an
// unpaid cycle — see ChargeResellerTierFeesCommand's own docblock.
Schedule::command('app:charge-reseller-tier-fees')
    ->daily()
    ->name('reseller-tier-fee-charge')
    ->withoutOverlapping();
