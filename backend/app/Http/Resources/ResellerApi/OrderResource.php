<?php

namespace App\Http\Resources\ResellerApi;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order placed against your Purchase Balance. The delivery webhook body is
 * this object plus `event`, `event_id` and `occurred_at`.
 *
 * @property Order $resource
 */
#[SchemaName('Order')]
class OrderResource extends JsonResource
{
    // ADR-084 decision 2: the ONE reseller-safe shape of an order, used by every
    // Reseller API order response and by the delivery-webhook body, so they cannot
    // drift. It never carries cost, profit, the tier markup, a supplier's identity
    // or an internal id. PUBLISHED: Scramble writes the public `Order` schema from
    // this class, so every docblock inside `toArray()` reaches docs.pekangame.space.
    // Write those for partners; internal notes go in `//` comments like this one.
    // OrderWireShapeTest pins the wire shape; scripts/check-docs.mjs fails CI if a
    // private term reaches the published spec.

    /** Never wrapped in `data`: the wire shape predates this class. */
    public static $wrap = null;

    /**
     * @param  array<int, LedgerEntry|null>|null  $walletRefundsByOrderId  preloaded refunds for a page of orders (`Order::walletRefundEntriesFor`), else looked up per order
     */
    public function __construct(Order $order, private readonly ?array $walletRefundsByOrderId = null)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $order->loadMissing(['game', 'package']);
        $walletRefund = $this->walletRefundsByOrderId !== null
            ? ($this->walletRefundsByOrderId[$order->id] ?? null)
            : $order->walletRefundLedgerEntry();

        $productCode = $order->game?->reseller_code !== null
            ? $order->game->reseller_code.'-'.($order->package?->denomination ?? $order->package?->catalog_code)
            : null;

        return [
            /**
             * PekanGame's order number. Treat it as an opaque string: today it is `PG-` and
             * 12 capital letters or digits, but the length is not part of the contract.
             *
             * @example PG-7QK2M9X4RJ
             */
            'order_number' => (string) $order->order_number,
            /**
             * The product code the order was placed with. `null` only when the
             * game no longer has a code in the catalogue.
             *
             * @var string|null
             *
             * @example MLMY-86
             */
            'product_code' => $productCode,
            /** @example 123456789 */
            'player_id' => (string) $order->player_id,
            /**
             * @var string|null
             *
             * @example 2201
             */
            'server_id' => $order->server_id,
            /**
             * What was charged to your Purchase Balance, in sen (RM 1.00 = 100).
             *
             * @example 6300
             */
            'price_sen' => (int) $order->selling_price,
            /**
             * Always `paid` for a Purchase Balance order.
             *
             * @var PaymentStatus
             */
            'payment_status' => $order->payment_status->value,
            /** @var DeliveryStatus */
            'delivery_status' => $order->delivery_status->value,
            /** `true` once the order has been refunded to your Purchase Balance (fully, or the undelivered share). */
            'wallet_refunded' => $walletRefund !== null,
            /**
             * The refund to your Purchase Balance, or `null` when there is none.
             *
             * @var array{amount_sen: int, refunded_at: string}|null
             */
            'wallet_refund' => $walletRefund !== null ? [
                'amount_sen' => (int) $walletRefund->amount,
                'refunded_at' => $walletRefund->created_at?->toIso8601String(),
            ] : null,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'created_at' => $order->created_at?->toIso8601String(),
            /**
             * Set when the order is delivered.
             *
             * @var string|null
             *
             * @format date-time
             */
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }
}
