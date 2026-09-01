<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-059 decision 2: the reseller's own storefront orders — list +
 * detail, read-only. `Order` carries `BelongsToReseller` (ADR-057), so
 * `reseller.context` already constrains every query here to this tenant;
 * there is no `where('reseller_id', …)` to write and no way to widen it
 * from a request parameter.
 *
 * The response shape is deliberately narrow (mirrors TrackOrderController
 * / `backend/AGENTS.md`'s public-response rule): the reseller sees their
 * own margin (`reseller_profit`, `reseller_markup_pct`) but never
 * `cost_price` / `standard_selling_price` / `platform_profit` or any
 * supplier / payment-gateway internal field.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', array_column(PaymentStatus::cases(), 'value'))],
            'delivery_status' => ['nullable', 'string', 'in:'.implode(',', array_column(DeliveryStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['game:id,name,slug', 'package:id,name'])
            ->when($validated['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($validated['delivery_status'] ?? null, fn ($q, $v) => $q->where('delivery_status', $v))
            ->when($validated['search'] ?? null, fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('order_number', 'like', "%{$v}%")
                    ->orWhere('reference_number', 'like', "%{$v}%")
                    ->orWhere('customer_email', 'like', "%{$v}%");
            }))
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20)
            ->through(fn (Order $order): array => $this->shape($order));

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['game:id,name,slug', 'package:id,name'])
            ->where('order_number', $orderNumber)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException('No order found with that order number.');
        }

        return response()->json($this->shape($order, detail: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Order $order, bool $detail = false): array
    {
        $base = [
            'order_number' => $order->order_number,
            'reference_number' => $order->reference_number,
            'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
            'package_name' => $order->package?->name,
            'final_amount' => $order->final_amount,
            'reseller_profit' => $order->reseller_profit,
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        return $base + [
            'customer_email' => $order->customer_email,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'reseller_markup_pct' => (float) $order->reseller_markup_pct,
            'voucher_discount' => $order->voucher_discount,
            'transaction_fee' => $order->transaction_fee,
            'payment_method' => $order->payment_method,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
        ];
    }
}
