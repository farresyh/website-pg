<?php

namespace App\Http\Controllers\ResellerApi;

use App\Http\Requests\ResellerApi\PlaceOrderRequest;
use App\Models\Order;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Pricing\PricingService;
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
 * `PricingService` prices it, `ResellerOrderPlacementService` places
 * it) — this controller only orchestrates and shapes the response.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly PricingService $pricing,
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

        if ($reseller->reseller_tier_id === null) {
            return response()->json(['message' => 'No wallet tier assigned to this reseller account.'], 422);
        }

        // ADR-073 decision 4's contract expects the price pre-resolved —
        // same "caller resolves, service just spends" division as
        // CheckoutRequest already established for CheckoutService.
        $tierPricing = $this->pricing->calculateForAffiliate(
            $package->cost_price,
            $package->standard_selling_price,
            (float) $reseller->tier->markup_percent,
            0.0,
        );

        try {
            $order = $this->placement->placeOrder($reseller, new ResellerOrderPlacementRequest(
                playerId: $data['player_id'],
                serverId: $data['server_id'] ?? null,
                costPriceSen: $tierPricing->costPrice,
                standardSellingPriceSen: $tierPricing->standardSellingPrice,
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
