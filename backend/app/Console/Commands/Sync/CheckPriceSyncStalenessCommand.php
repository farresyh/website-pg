<?php

namespace App\Console\Commands\Sync;

use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Backup\BackupFailureAlerter;
use App\Support\MalaysiaTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-015 2026-10-17 addendum (PRD §16 item 106): emails every admin when
 * a supplier's prices haven't synced successfully for
 * `api_config['price_sync_stale_alert_minutes']` (blank or not a number =
 * 90, 0 = off), and once more when they sync again.
 *
 * Judged from `suppliers.last_price_sync_at` (written by
 * SyncSupplierPricesJob), not by counting runs: a "Sync Now" click or a
 * changed interval doesn't move the threshold. Runs from the scheduler,
 * not the queue, so it still fires when Horizon is down and the sync job
 * never runs. `price_sync_alerted_at` is the open-alert state; each
 * transition is a conditional UPDATE, so a run can't send an email twice.
 *
 * A supplier reactivated after a long pause may get one stale email and
 * its recovery email within the next sync interval.
 */
#[Signature('app:check-price-sync-staleness')]
#[Description('Email admins when a supplier\'s prices stop syncing, and when they recover.')]
class CheckPriceSyncStalenessCommand extends Command
{
    public const DEFAULT_THRESHOLD_MINUTES = 90;

    public function handle(BackupFailureAlerter $alerter): int
    {
        $suppliers = Supplier::query()->where('is_active', true)->get()
            ->filter(fn (Supplier $supplier) => $supplier->isSyncable());

        foreach ($suppliers as $supplier) {
            $lastSync = $supplier->last_price_sync_at ?? $supplier->created_at;
            $alertedAt = $supplier->price_sync_alerted_at;

            // Recovery is judged against the alert itself, not the
            // threshold, so turning the threshold off can't fake one.
            if ($alertedAt !== null) {
                if ($lastSync->greaterThan($alertedAt) && $this->transition($supplier, from: $alertedAt, to: null)) {
                    $alerter->alert(
                        "{$supplier->name} price sync recovered",
                        "{$supplier->name} prices synced again at {$this->myt($lastSync)} MYT.",
                        'Price Sync',
                    );
                }

                continue;
            }

            $threshold = $this->thresholdMinutes($supplier);

            if ($threshold > 0 && $lastSync->lessThanOrEqualTo(now()->subMinutes($threshold)) && $this->transition($supplier, from: null, to: now())) {
                $minutes = (int) $lastSync->diffInMinutes(now());
                $alerter->alert(
                    "{$supplier->name} price sync is stale",
                    "{$supplier->name} prices have not synced since {$this->myt($lastSync)} MYT ({$minutes} minutes; alert threshold {$threshold}). "
                        ."Prices and deactivations from this supplier are not being updated. Last error: {$this->lastError($supplier)}",
                    'Price Sync',
                );
            }
        }

        return self::SUCCESS;
    }

    private function thresholdMinutes(Supplier $supplier): int
    {
        $value = $supplier->api_config['price_sync_stale_alert_minutes'] ?? null;

        return is_numeric($value) ? (int) $value : self::DEFAULT_THRESHOLD_MINUTES;
    }

    private function transition(Supplier $supplier, mixed $from, mixed $to): bool
    {
        $query = Supplier::query()->whereKey($supplier->id);
        $from === null ? $query->whereNull('price_sync_alerted_at') : $query->where('price_sync_alerted_at', $from);

        return $query->update(['price_sync_alerted_at' => $to]) === 1;
    }

    private function lastError(Supplier $supplier): string
    {
        $run = PriceSyncRun::query()->whereIn('status', ['partial', 'failed'])->latest('id')->first();

        $failure = collect($run?->stats['failed_suppliers'] ?? [])->firstWhere('slug', $supplier->slug);

        return $failure['error'] ?? $run?->error_message ?? 'none recorded, see Sync History.';
    }

    private function myt(mixed $time): string
    {
        return $time->copy()->setTimezone(MalaysiaTime::TIMEZONE)->format('j M Y H:i');
    }
}
