<?php

namespace App\Support;

use App\Models\LedgerEntry;
use App\Models\Order;

/**
 * ADR-084 decision 2: the ONE narrow, reseller-safe shape for an order —
 * shared by the Reseller API's `GET`/`POST /v1/orders*` responses and by
 * the delivery-webhook body (PR-3). Never carries a reseller-private
 * field (`cost_price`, `platform_profit`, the tier markup, any supplier
 * identity, an internal id) — `price_sen` is always the caller's own
 * tier-adjusted price.
 *
 * Mirrors `ContactMask` / `TrackOrderController::customerSafePayload()`'s
 * role for the storefront's public order-status contract: one place, so
 * the API and the webhook can never drift on what a reseller may see.
 */
final class ResellerOrderPayload
{
    /**
     * @param  array<int, LedgerEntry|null>|null  $walletRefundsByOrderId
     * @return array<string, mixed>
     */
    public static function for(Order $order, ?array $walletRefundsByOrderId = null): array
    {
        $order->loadMissing(['game', 'package']);
        $walletRefund = $walletRefundsByOrderId !== null
            ? ($walletRefundsByOrderId[$order->id] ?? null)
            : $order->walletRefundLedgerEntry();

        $productCode = $order->game?->reseller_code !== null
            ? $order->game->reseller_code.'-'.($order->package?->denomination ?? $order->package?->catalog_code)
            : null;

        return [
            'order_number' => $order->order_number,
            'product_code' => $productCode,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'price_sen' => (int) $order->selling_price,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'wallet_refunded' => $walletRefund !== null,
            'wallet_refund' => $walletRefund !== null ? [
                'amount_sen' => $walletRefund->amount,
                'refunded_at' => $walletRefund->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $order->created_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }
}
