<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSupplierPricesJob;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceSyncRun;
use App\Services\Sync\PendingReactivationFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SYNC-4/ADR-015 decision #5: "Sync All Prices Now" dispatches
 * SyncSupplierPricesJob async — the admin panel never blocks on a
 * live Gamevion call. `show()` is what `/middleware/price-sync` polls
 * while a run is `queued`/`running`. `index()`/`stats()`/`details()`
 * are ADR-016's Sync History, stat cards, and Sync Details modal.
 */
class PriceSyncController extends Controller
{
    public function __construct(private readonly PendingReactivationFinder $pendingReactivations)
    {
    }

    /**
     * ADR-016 decision #1: Sync History, most recent run first.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        return response()->json(
            PriceSyncRun::query()
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    /**
     * ADR-016 decision #1: the stat cards. "Total Games"/"Active
     * Packages" are simple platform-wide counts (only one supplier
     * exists today, so no per-supplier scoping is needed yet). Pending
     * Reactivation count reuses the same live query the queue itself
     * uses, not a separate cached number that could drift from it.
     * `pending_price_change_count` (ADR-025 decision #8) is the same
     * live-count pattern for the sixth section.
     */
    public function stats(): JsonResponse
    {
        $lastRun = PriceSyncRun::query()->latest()->first();

        return response()->json([
            'total_games' => Game::query()->count(),
            'active_packages' => Package::query()->where('is_active', true)->count(),
            'pending_reactivation_count' => $this->pendingReactivations->find()->count(),
            'pending_price_change_count' => PendingPriceChange::query()->where('status', 'pending')->count(),
            'last_sync_status' => $lastRun?->status,
            'last_sync_at' => $lastRun?->finished_at ?? $lastRun?->created_at,
        ]);
    }

    /**
     * ADR-016 decision #1/#2: Sync Details modal — per-game grouped
     * price/cost diffs and deactivations for one run. Games Total in
     * the response is the count of distinct games touched by either
     * log, matching decision #2's "count of distinct games touched"
     * definition (Stage 1 is one atomic call, so real per-game
     * success/failure isn't a state this system can produce).
     */
    public function details(PriceSyncRun $priceSyncRun): JsonResponse
    {
        $priceChanges = $priceSyncRun->priceChangeLogs()->with('package.game')->get();
        $deactivations = $priceSyncRun->deactivationLogs()->with('package.game')->get();

        $gameIds = $priceChanges->pluck('package.game_id')
            ->merge($deactivations->pluck('package.game_id'))
            ->filter()
            ->unique();

        $games = $gameIds->map(function (int $gameId) use ($priceChanges, $deactivations) {
            $gamePriceChanges = $priceChanges->filter(fn ($log) => $log->package->game_id === $gameId);
            $gameDeactivations = $deactivations->filter(fn ($log) => $log->package->game_id === $gameId);

            return [
                'game' => $gamePriceChanges->first()?->package->game ?? $gameDeactivations->first()?->package->game,
                'price_changes' => $gamePriceChanges->values()->map(fn ($log) => [
                    'package' => ['id' => $log->package->id, 'name' => $log->package->name],
                    'old_cost_price' => $log->old_cost_price,
                    'new_cost_price' => $log->new_cost_price,
                    'old_standard_selling_price' => $log->old_standard_selling_price,
                    'new_standard_selling_price' => $log->new_standard_selling_price,
                ]),
                'deactivated_packages' => $gameDeactivations->values()->map(fn ($log) => [
                    'id' => $log->package->id,
                    'name' => $log->package->name,
                ]),
            ];
        })->values();

        return response()->json([
            'run' => $priceSyncRun,
            'games_touched' => $gameIds->count(),
            'games' => $games,
        ]);
    }

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
