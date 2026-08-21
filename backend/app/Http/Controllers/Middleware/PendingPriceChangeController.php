<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;

/**
 * ADR-025 decision #8: sixth Price Sync Center section — a supplier
 * price swing large enough to cross the configured threshold, blocked
 * from PackagePriceSyncService::propagatePrice()'s write until an
 * admin Approves or Dismisses it here.
 */
class PendingPriceChangeController extends Controller
{
    public function __construct(private readonly PackageMarkupService $markup)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            PendingPriceChange::query()
                ->with('package.game', 'package.supplier')
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->get(),
        );
    }

    /**
     * Decision #6: reseller_cost_price is recomputed from the
     * package's *live* markup_percent at approval time, not whatever
     * was frozen when the anomaly was first flagged — an admin may
     * have edited the markup in between.
     */
    public function approve(PendingPriceChange $pendingPriceChange): JsonResponse
    {
        $this->assertPending($pendingPriceChange);

        $package = $pendingPriceChange->package;
        $newResellerCostPrice = $this->markup->calculateResellerCostPrice(
            $pendingPriceChange->proposed_cost_price,
            (float) $package->markup_percent,
        );

        PriceChangeLog::query()->create([
            'price_sync_run_id' => $pendingPriceChange->price_sync_run_id,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'new_cost_price' => $pendingPriceChange->proposed_cost_price,
            'old_reseller_cost_price' => $package->reseller_cost_price,
            'new_reseller_cost_price' => $newResellerCostPrice,
        ]);

        $package->update([
            'cost_price' => $pendingPriceChange->proposed_cost_price,
            'reseller_cost_price' => $newResellerCostPrice,
            'is_active' => true,
            'deactivated_reason' => null,
            'deactivated_at' => null,
        ]);

        $pendingPriceChange->update(['status' => 'approved']);
        $this->forgetCaches($package->game_id);

        return response()->json($package);
    }

    /**
     * Decision #7: "the old price was fine all along" — reactivates
     * at the already-proven-safe price in one action, no separate trip
     * to /admin/games to flip is_active back on.
     */
    public function dismiss(PendingPriceChange $pendingPriceChange): JsonResponse
    {
        $this->assertPending($pendingPriceChange);

        $package = $pendingPriceChange->package;
        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
        $pendingPriceChange->update(['status' => 'dismissed']);
        $this->forgetCaches($package->game_id);

        return response()->json($package);
    }

    private function assertPending(PendingPriceChange $pendingPriceChange): void
    {
        abort_unless($pendingPriceChange->status === 'pending', 422, 'This price change has already been resolved.');
    }

    private function forgetCaches(int $gameId): void
    {
        GameController::forgetPackagesCache($gameId);
        GameController::forgetIndexCache();
    }
}
