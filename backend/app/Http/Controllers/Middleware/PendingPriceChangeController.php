<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Middleware\ApproveAllPendingPriceChangesRequest;
use App\Models\PendingPriceChange;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-138 decisions 12 and 14: a held price, in the Price Sync Center. The
 * package keeps selling meanwhile; Approve applies the held price now.
 */
class PendingPriceChangeController extends Controller
{
    public function __construct(private readonly PackagePriceSyncService $priceSync) {}

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
     * ADR-025 decision 6: the selling price comes from the package's live
     * markup at this moment, not from when the swing was first held.
     */
    public function approve(Request $request, PendingPriceChange $pendingPriceChange): JsonResponse
    {
        $package = $this->priceSync->approveHeld($pendingPriceChange, $request->user()?->id);
        abort_if($package === null, 422, 'This price change has already been resolved.');

        $this->forgetCaches([$package->game_id]);

        return response()->json($package);
    }

    /** ponytail: one request, row by row; move to a queued job if a real mass change times out. */
    public function approveAll(ApproveAllPendingPriceChangesRequest $request): JsonResponse
    {
        $rows = PendingPriceChange::query()
            ->where('status', 'pending')
            ->whereHas('package', fn ($q) => $q->where('supplier_id', $request->validated('supplier_id')))
            ->get();

        $packages = $rows->map(fn (PendingPriceChange $row) => $this->priceSync->approveHeld($row, $request->user()?->id))->filter();

        $this->forgetCaches($packages->pluck('game_id')->unique()->all());

        return response()->json(['approved' => $packages->count()]);
    }

    /** @param  list<int>  $gameIds */
    private function forgetCaches(array $gameIds): void
    {
        foreach ($gameIds as $gameId) {
            GameController::forgetPackagesCache($gameId);
        }

        GameController::forgetIndexCache();
    }
}
