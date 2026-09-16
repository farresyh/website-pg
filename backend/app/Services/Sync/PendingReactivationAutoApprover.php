<?php

namespace App\Services\Sync;

use App\Http\Controllers\GameController;
use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\PackageReactivationLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Carbon;

/**
 * ADR-100 — the automated half of Pending Reactivation, running once
 * per supplier immediately after PackagePriceSyncService::apply() in
 * SyncSupplierPricesJob, right after that run's own writes to
 * `supplier_products` (cutoff/streak included) have landed. Off by
 * default (`config('packages.pending_reactivation_auto_approve')`) —
 * ADR-015 decision #3's manual queue is the only thing that happens
 * until a founder explicitly turns this on.
 *
 * Two independent triggers, deliberately not one merged threshold:
 *
 * - **Cutoff window** (Digiflazz only — the one adapter that maps
 *   `start_cut_off`/`end_cut_off`): a package whose supplier item
 *   reports active again, where the current Asia/Jakarta time falls
 *   inside that item's own documented daily cutoff window, approves
 *   immediately. No waiting — Digiflazz's own schedule is the
 *   evidence, not a signal this project needs to independently trust
 *   over several syncs. An ambiguous `cutoff_start === cutoff_end`
 *   (Digiflazz shows this for both "no cutoff set" and a genuine
 *   instant-midnight window — indistinguishable without more data)
 *   is treated as "no cutoff data" and falls through to the stability
 *   gate below, never silently suppressed.
 * - **Stability confirmed**: every other case (every non-Digiflazz
 *   supplier; a Digiflazz item with no usable cutoff data; a Digiflazz
 *   item confirmed active *outside* its own cutoff window — a
 *   genuine, unexplained flap). Requires `consecutive_active_syncs`
 *   (ProductSyncService's own per-row streak) to reach
 *   `reactivation_stability_syncs`, AND the package's own
 *   `deactivation_logs` history over the trailing
 *   `reactivation_flap_window_days` to stay at or under
 *   `reactivation_flap_limit_per_14_days` — a package that has
 *   genuinely flapped past that limit is treated as structurally
 *   unstable and stays manual regardless of streak. A historical
 *   deactivation whose own timestamp falls inside the package's
 *   *current* cutoff window is excluded from that count — it would
 *   otherwise punish exactly the predictable, cutoff-driven case the
 *   trigger above exists to handle automatically (a package like
 *   Digiflazz's own daily-cutoff SKUs can flap 10 times in 14 days and
 *   never once count against it).
 *
 * Every approval this class makes writes a `PackageReactivationLog`
 * row (audit trail, decision Q10) — a manual Approve via
 * PendingReactivationController never does, by design; that table is
 * scoped to exactly what this class decided on its own.
 */
final class PendingReactivationAutoApprover
{
    public function __construct(private readonly PendingReactivationFinder $finder) {}

    public function run(Supplier $supplier, ?int $priceSyncRunId): int
    {
        if (! (bool) config('packages.pending_reactivation_auto_approve')) {
            return 0;
        }

        $pending = $this->finder->find($supplier->id);

        if ($pending->isEmpty()) {
            return 0;
        }

        $supplierProducts = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('external_ref', $pending->pluck('supplier_package_ref'))
            ->get()
            ->keyBy('external_ref');

        $approved = 0;

        foreach ($pending as $package) {
            $product = $supplierProducts->get($package->supplier_package_ref);

            if (! $product) {
                continue;
            }

            $trigger = match (true) {
                $this->withinCutoffWindow($product, Carbon::now()) => 'cutoff_window',
                $this->stabilityConfirmed($package, $product) => 'stability_confirmed',
                default => null,
            };

            if ($trigger === null) {
                continue;
            }

            $this->approve($package, $priceSyncRunId, $trigger);
            $approved++;
        }

        return $approved;
    }

    private function stabilityConfirmed(Package $package, SupplierProduct $product): bool
    {
        if ($product->consecutive_active_syncs < (int) config('packages.reactivation_stability_syncs')) {
            return false;
        }

        return $this->nonCutoffFlapCount($package, $product) <= (int) config('packages.reactivation_flap_limit_per_14_days');
    }

    private function nonCutoffFlapCount(Package $package, SupplierProduct $product): int
    {
        $windowDays = (int) config('packages.reactivation_flap_window_days');

        return DeactivationLog::query()
            ->where('package_id', $package->id)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->get(['created_at'])
            ->reject(fn (DeactivationLog $log) => $this->withinCutoffWindow($product, $log->created_at))
            ->count();
    }

    /**
     * Digiflazz's own window can cross midnight (e.g. `23:00`-`01:00`)
     * — a plain `start <= now <= end` string comparison breaks for
     * that case, so the wraparound branch below mirrors it.
     */
    private function withinCutoffWindow(SupplierProduct $product, Carbon $at): bool
    {
        if ($product->cutoff_start === null || $product->cutoff_end === null) {
            return false;
        }

        // Q7 — ambiguous, treated as "no cutoff data" rather than a
        // genuine instant-midnight window.
        if ($product->cutoff_start === $product->cutoff_end) {
            return false;
        }

        // "hh:mm", Asia/Jakarta local time per Digiflazz's own docs.
        $time = $at->copy()->setTimezone('Asia/Jakarta')->format('H:i');

        if ($product->cutoff_start <= $product->cutoff_end) {
            return $time >= $product->cutoff_start && $time <= $product->cutoff_end;
        }

        return $time >= $product->cutoff_start || $time <= $product->cutoff_end;
    }

    private function approve(Package $package, ?int $priceSyncRunId, string $trigger): void
    {
        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

        PackageReactivationLog::query()->create([
            'package_id' => $package->id,
            'price_sync_run_id' => $priceSyncRunId,
            'trigger' => $trigger,
        ]);

        // Matches PendingReactivationController::forgetCaches()'s own
        // per-package pattern exactly — default `withIndex=true`
        // purges both in one call.
        GameController::forgetPackagesCache($package->game_id);
    }
}
