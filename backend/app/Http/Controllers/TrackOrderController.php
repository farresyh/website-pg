<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformSettings;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentReconciliationService;
use App\Support\ContactMask;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public "Track Order" lookup (ADR-011 — no Customer auth exists).
 * `order_number` is `PG-` + 12 random base36 chars (OrderNumberService —
 * ~62 bits from a CSPRNG), high enough entropy that knowing it is treated
 * as proof of ownership — the same trust model as a courier tracking
 * number or a Stripe payment link, no email/second factor required.
 *
 * Deliberately returns a narrow, customer-safe subset of Order —
 * never `cost_price`/`standard_selling_price`/`platform_profit`/
 * `affiliate_profit` (internal financial data) or
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
    private const CHECK_WINDOW_SECONDS = 10;

    public function show(string $orderNumber, StorefrontBrand $brand): JsonResponse
    {
        $order = Order::query()
            ->forStorefrontBrand($brand->get())
            ->with(['game:id,name,slug,validation_rules', 'package:id,name', 'review:id,order_id'])
            ->where('order_number', $orderNumber)
            ->first();

        // Same 404 body whether the number is unknown or belongs to
        // another brand's storefront — never confirm an order exists
        // under a storefront it wasn't placed on.
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
    /**
     * ADR-110 2026-10-29 addendum: the customer is back on the status page
     * (CHIP's `failure_redirect` lands here). Ask CHIP once about a Pending
     * order so a cancelled attempt fails now (voucher and quota given back)
     * instead of at the next sweep. Never worse than `show()`: a CHIP lookup
     * that fails or is skipped just returns the order as it is.
     */
    public function checkPayment(string $orderNumber, StorefrontBrand $brand, PaymentReconciliationService $reconciliation): JsonResponse
    {
        $order = Order::query()
            ->forStorefrontBrand($brand->get())
            ->with(['game:id,name,slug,validation_rules', 'package:id,name', 'review:id,order_id'])
            ->where('order_number', $orderNumber)
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'No order found with that order number.'], 404);
        }

        // One CHIP call per order per window, however often the page asks.
        if ($order->payment_status === PaymentStatus::Pending
            && $order->payment_ref !== null
            && Cache::add("check-payment:{$order->id}", 1, self::CHECK_WINDOW_SECONDS)) {
            try {
                $reconciliation->reconcileOrder($order, earlyCheck: true);
                $order->refresh();
            } catch (Throwable $e) {
                Log::warning('Status-page payment check failed', ['order_number' => $order->order_number, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(self::customerSafePayload($order));
    }

    public static function customerSafePayload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
            'package_name' => $order->package?->name,
            // ADR-125 decisions 31/38: a login order's identifier is half
            // its login, so it is masked; a UID order is unchanged.
            'player_id' => $order->isLoginOrder() ? $order->publicPlayerLabel() : $order->player_id,
            'server_id' => $order->isLoginOrder() ? null : $order->server_id,
            'fulfilment_kind' => $order->isLoginOrder() ? 'login' : 'uid',
            // The label the customer typed it under, e.g. "Roblox email".
            'player_label' => $order->isLoginOrder() ? ($order->game?->identifierField()['label'] ?? 'Login') : null,
            // ADR-124 decision 9: the login is wiped about this many hours
            // after the order closes.
            'login_wipe_hours' => $order->isLoginOrder() ? PlatformSettings::current()->credential_close_grace_hours : null,
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
            // ADR-126 decision 10: a joki order's climb and latest rank.
            // Labels only — never the payout, share or penjoki.
            'joki' => $order->isJoki() && $order->jokiOrder !== null ? [
                'from' => $order->jokiOrder->from_rank['label'] ?? null,
                'to' => $order->jokiOrder->to_rank['label'] ?? null,
                'current' => $order->jokiOrder->current_rank['label'] ?? null,
                'current_at' => $order->jokiOrder->current_rank_at?->toISOString(),
                'is_express' => $order->jokiOrder->is_express,
            ] : null,
        ];
    }
}
