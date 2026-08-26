<?php

use App\Jobs\SyncSupplierPricesJob;
use App\Models\PriceSyncRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-015 decision #6: written now so it activates for free the
// moment a real OS cron (`* * * * * php artisan schedule:run`) exists
// on a deployed host — this project currently runs on local Herd
// only, so this entry is inert today, not a claim that scheduled
// Price Sync is actually running in production yet. Writes its own
// PriceSyncRun row (triggered_by='system') the same way the manual
// "Sync All Prices Now" trigger does, so Sync History (ADR-016) shows
// both uniformly once that UI exists.
$priceSyncIntervalMinutes = config('packages.price_sync_interval_minutes');

Schedule::call(function () {
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
