<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 2: read-only
 * order history for a Reseller (wallet) portal account — order
 * *placement* stays exclusively the API (PR-E) / Bot (PR-F) channels.
 *
 * `Order` carries no BelongsToAffiliate-style tenant scope for
 * `wallet_reseller_id` (that scope is `Affiliate`-only, ADR-057) and
 * `affiliate.context` is deliberately not applied to this route group
 * (a Reseller session has no meaning under it) — every query here
 * filters `wallet_reseller_id` explicitly.
 *
 * Response shape mirrors `ResellerApi\OrderController`'s own
 * reseller-safe narrowing — never `cost_price`/`platform_profit`/
 * `affiliate_profit` (`backend/AGENTS.md`'s public-response discipline).
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        $validated = $request->validate([
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', array_column(PaymentStatus::cases(), 'value'))],
            'delivery_status' => ['nullable', 'string', 'in:'.implode(',', array_column(DeliveryStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->where('wallet_reseller_id', $reseller->id)
            ->with(['game:id,name,slug', 'package:id,name'])
            ->when($validated['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($validated['delivery_status'] ?? null, fn ($q, $v) => $q->where('delivery_status', $v))
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);
        $refunds = Order::walletRefundEntriesFor($orders->getCollection());
        $orders->through(fn (Order $order): array => self::shape($order, refundsByOrderId: $refunds));

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $reseller = $this->reseller($request);

        $order = Order::query()
            ->where('wallet_reseller_id', $reseller->id)
            ->where('order_number', $orderNumber)
            ->with(['game:id,name,slug', 'package:id,name'])
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException('No order found with that order number.');
        }

        return response()->json(self::shape($order, detail: true));
    }

    /**
     * @param  array<int, LedgerEntry|null>|null  $refundsByOrderId
     * @return array<string, mixed>
     */
    private static function shape(Order $order, bool $detail = false, ?array $refundsByOrderId = null): array
    {
        $walletRefund = $refundsByOrderId !== null
            ? ($refundsByOrderId[$order->id] ?? null)
            : $order->walletRefundLedgerEntry();
        $base = [
            'order_number' => $order->order_number,
            'reference_number' => $order->reference_number,
            'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
            'package_name' => $order->package?->name,
            'final_amount' => $order->final_amount,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'wallet_refunded' => $walletRefund !== null,
            'wallet_refund' => $walletRefund !== null ? [
                'amount_sen' => $walletRefund->amount,
                'refunded_at' => $walletRefund->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        return $base + [
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }
}
