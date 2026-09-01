<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;

/**
 * Public "Track Order" lookup (ADR-011 — no Customer auth exists).
 * `order_number` is `PG-` + 12 random base36 chars (OrderNumberService —
 * ~62 bits from a CSPRNG), high enough entropy that knowing it is treated
 * as proof of ownership — the same trust model as a courier tracking
 * number or a Stripe payment link, no email/second factor required.
 *
 * Deliberately returns a narrow, customer-safe subset of Order —
 * never `cost_price`/`standard_selling_price`/`platform_profit`/
 * `reseller_profit` (internal financial data) or
 * `supplier_response`/`payment_ref`/`supplier_ref` (internal
 * operational fields) — only what the paying customer needs to see
 * their own order's status.
 *
 * ADR-065: also carries the buyer's own contact details **masked**
 * (`ContactMask`, server-side — the raw values never leave the
 * backend here) and the customer-facing payment breakdown
 * (`payment_method` / `selling_price` / `voucher_discount` /
 * `transaction_fee`, all money the buyer already saw at checkout).
 * `OrderStatusUpdated::broadcastWith()` mirrors this shape exactly —
 * `TrackedOrderSchema` is the one contract for both the poll and the
 * push, keep them identical.
 */
class TrackOrderController extends Controller
{
    public function show(string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['game:id,name,slug', 'package:id,name', 'review:id,order_id'])
            ->where('order_number', $orderNumber)
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'No order found with that order number.'], 404);
        }

        return response()->json(self::customerSafePayload($order));
    }

    /**
     * The one shape shared by this endpoint and `OrderStatusUpdated`
     * (ADR-047 / ADR-065). `TrackedOrderSchema` on the storefront is the
     * matching contract for both.
     *
     * @return array<string, mixed>
     */
    public static function customerSafePayload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
            'package_name' => $order->package?->name,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            // ADR-065: masked contact — recognition aid for the buyer,
            // nothing usable for a stranger with the order number.
            'customer_name_masked' => ContactMask::name($order->customer_name),
            'customer_email_masked' => ContactMask::email($order->customer_email),
            'customer_phone_masked' => ContactMask::phone($order->customer_phone),
            // ADR-065: customer-facing payment breakdown — the charged
            // price (never `standard_selling_price`), the fee and the
            // voucher deduction the buyer already saw at checkout.
            'payment_method' => $order->payment_method,
            'selling_price' => $order->selling_price,
            'voucher_discount' => $order->voucher_discount ?? 0,
            'transaction_fee' => $order->transaction_fee,
            'final_amount' => $order->final_amount,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'created_at' => $order->created_at?->toISOString(),
            // ADR-053 decisions 3/4 — drives the storefront's review
            // popup: shown once delivery_status=delivered and this is
            // still false, never re-shown once a review exists.
            'has_review' => $order->review !== null,
        ];
    }
}
