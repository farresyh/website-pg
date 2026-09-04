<?php

namespace App\Http\Controllers\ResellerApi;

use App\Http\Requests\ResellerApi\PlaceOrderRequest;
use App\Models\Order;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\ResellerCatalogService;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-074 decision 3: the Reseller API's order-create/status
 * endpoints — the concrete HTTP surface over ADR-073 decision 4's
 * internal contract. Every piece of actual money/order logic is
 * someone else's (`ResellerCatalogService` resolves the code,
 * `ResellerOrderPlacementService` resolves the tier price and places
 * the order) — this controller only orchestrates and shapes the
 * response.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly ResellerOrderPlacementService $placement,
    ) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $data = $request->validated();

        $package = $this->catalog->resolveByCode($data['product_code']);
        if ($package === null) {
            return response()->json(['message' => 'Unknown or currently unavailable product_code.'], 422);
        }

        // No separate reseller_tier_id null-check here — placeOrder()
        // itself throws NoResellerTierAssignedException for that (caught
        // below), a manual duplicate guard was removed as dead code
        // during the live-verify walkthrough.
        //
        // ADR-073 decision 4's contract takes the RAW catalog cost/standard
        // prices, not a pre-applied tier markup — ResellerOrderPlacementService
        // resolves the reseller's own tier and runs
        // PricingService::calculateForAffiliate() itself (same call this
        // controller would otherwise make redundantly: costPrice/
        // standardSellingPrice pass through that call unchanged, so
        // calling it here first only to re-extract them back out was
        // dead work — caught during the live-verify walkthrough).
        try {
            $order = $this->placement->placeOrder($reseller, new ResellerOrderPlacementRequest(
                playerId: $data['player_id'],
                serverId: $data['server_id'] ?? null,
                costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price,
                idempotencyKey: $data['idempotency_key'],
                supplierProductRef: $package->supplier_package_ref,
                gameId: $package->game_id,
                packageId: $package->id,
                supplierId: $package->supplier_id,
            ));
        } catch (ResellerInactiveException|NoResellerTierAssignedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InsufficientBalanceException) {
            return response()->json(['message' => 'Insufficient wallet balance.'], 422);
        }

        return response()->json(self::publicOrder($order), 201);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $reseller = $this->reseller($request);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('wallet_reseller_id', $reseller->id)
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(self::publicOrder($order));
    }

    /**
     * Narrow, reseller-safe shape — never `cost_price`/`platform_profit`
     * (internal financial fields, `backend/AGENTS.md`'s own discipline),
     * mirrors `TrackOrderController::customerSafePayload()`'s role for
     * the storefront's own public order-status contract.
     *
     * @return array<string, mixed>
     */
    private static function publicOrder(Order $order): array
    {
        $order->loadMissing(['game', 'package']);

        $productCode = $order->game?->reseller_code !== null
            ? $order->game->reseller_code.'-'.($order->package?->denomination ?? $order->package?->catalog_code)
            : null;

        return [
            'order_number' => $order->order_number,
            'product_code' => $productCode,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'price_sen' => $order->selling_price,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'created_at' => $order->created_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }
}
