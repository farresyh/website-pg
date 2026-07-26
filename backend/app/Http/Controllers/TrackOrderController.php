<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Public "Track Order" lookup (ADR-011 — no Customer auth exists).
 * `order_number` is a ULID (OrderNumberService — 128 bits, ~80 of them
 * random), high enough entropy that knowing it is treated as proof of
 * ownership — the same trust model as a courier tracking number or a
 * Stripe payment link, no email/second factor required.
 *
 * Deliberately returns a narrow, customer-safe subset of Order —
 * never `cost_price`/`reseller_cost_price`/`platform_profit`/
 * `reseller_profit` (internal financial data) or
 * `supplier_response`/`payment_ref`/`supplier_ref` (internal
 * operational fields) — only what the paying customer needs to see
 * their own order's status.
 */
class TrackOrderController extends Controller
{
    public function show(string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['game:id,name,slug', 'package:id,name'])
            ->where('order_number', $orderNumber)
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'No order found with that order number.'], 404);
        }

        return response()->json([
            'order_number' => $order->order_number,
            'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
            'package_name' => $order->package?->name,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'final_amount' => $order->final_amount,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'created_at' => $order->created_at,
        ]);
    }
}
