<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSupplierPricesJob;
use App\Models\PriceSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SYNC-4/ADR-015 decision #5: "Sync All Prices Now" dispatches
 * SyncSupplierPricesJob async — the admin panel never blocks on a
 * live Gamevion call. `show()` is what `/middleware/price-sync` polls
 * while a run is `queued`/`running`.
 */
class PriceSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // ADR-016 decision #5: `triggered_by` distinguishes a manual
        // run from a scheduled one (routes/console.php's own
        // Schedule::call() sets 'system') — set here, not left null,
        // so Sync History can show who started each run once that UI
        // exists.
        $run = PriceSyncRun::query()->create([
            'status' => 'queued',
            'triggered_by' => $request->user()->name,
        ]);

        SyncSupplierPricesJob::dispatch($run);

        return response()->json($run, 201);
    }

    public function show(PriceSyncRun $priceSyncRun): JsonResponse
    {
        return response()->json($priceSyncRun);
    }
}
