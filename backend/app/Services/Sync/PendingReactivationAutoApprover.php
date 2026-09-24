<?php

namespace App\Services\Sync;

use App\Http\Controllers\GameController;
use App\Models\Package;
use App\Models\PackageReactivationLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\ComboPricingService;
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
 *   item confirmed active *outside* its own cutoff window). Requires
 *   `consecutive_active_syncs` (ProductSyncService's own per-row
 *   streak) to reach `reactivation_stability_syncs` — a sustained
 *   run of confirmed-active syncs, reset to 0 the instant a row goes
 *   inactive again, so a single lucky tick can never pass this gate
 *   on its own.
 *
 * ADR-100 addendum (2026-09-24) dropped the original flap-count gate
 * (`deactivation_logs` count over a trailing window, capped at a
 * configurable limit): live data showed it permanently locking out
 * exactly the popular SKUs it was meant to protect (Valorant Singapore
 * VP packages confirmed continuously active for ~20 hours straight,
 * per the founder's own Digiflazz dashboard, still blocked because
 * their flap history a few days back sat over the limit — a rolling
 * count with no way to age back under the limit while a package kept
 * legitimately flapping). Streak length alone is now the only signal:
 * long enough, it auto-approves regardless of how many times the
 * package flapped before this streak started.
 *
 * Every approval this class makes writes a `PackageReactivationLog`
 * row (audit trail, decision Q10) — a manual Approve via
 * PendingReactivationController never does, by design; that table is
 * scoped to exactly what this class decided on its own.
 */
final class PendingReactivationAutoApprover
{
    public function __construct(
        private readonly PendingReactivationFinder $finder,
        private readonly ComboPricingService $comboPricing,
    ) {}

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
                $this->stabilityConfirmed($product) => 'stability_confirmed',
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

    private function stabilityConfirmed(SupplierProduct $product): bool
    {
        return $product->consecutive_active_syncs >= (int) config('packages.reactivation_stability_syncs');
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

        // ADR-094 addendum (2026-09-24) — this component may complete
        // a combo that's been sitting cascade-deactivated.
        $this->comboPricing->cascadeReactivate($package, $priceSyncRunId);

        // Matches PendingReactivationController::forgetCaches()'s own
        // per-package pattern exactly — default `withIndex=true`
        // purges both in one call.
        GameController::forgetPackagesCache($package->game_id);
    }
}
