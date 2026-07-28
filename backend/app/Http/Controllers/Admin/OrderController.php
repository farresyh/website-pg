<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResendOrderDeliveryRequest;
use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\Order;
use App\Models\Package;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ORD-1..7 — read-only list + detail, plus retryDelivery (ORD-7's
 * resolve action — ADR-014) and resend (ADR-017's richer, package-swap
 * counterpart). Voucher issuance (the other ORD-7 action) and export
 * (ORD-5) remain out of scope here. See docs/prd.md §14.
 */
class OrderController extends Controller
{
    /**
     * `status` (ORD-2): need_action (paid but delivery failed —
     * customer's money is in, credits never arrived), processing
     * (delivery attempt in flight), completed (delivered), today,
     * or omitted for all.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with(['game:id,name', 'package:id,name']);

        match ($request->query('status')) {
            'need_action' => $query
                ->where('payment_status', PaymentStatus::Paid->value)
                ->where('delivery_status', DeliveryStatus::Failed->value),
            'processing' => $query->where('delivery_status', DeliveryStatus::Processing->value),
            'completed' => $query->where('delivery_status', DeliveryStatus::Delivered->value),
            'today' => $query->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]),
            default => null,
        };

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 25);

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString(),
        );
    }

    /**
     * ORD-6: customer info, game/package, payment info, supplier
     * response — everything needed to confirm a specific order's
     * outcome. Status-history timeline is deferred (would need its
     * own audit table; not built yet).
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load([
            'game', 'package', 'supplier', 'reseller',
            // ADR-017 decision #4: "Delivery Logs" — every resend
            // attempt, most recent first, alongside the package it
            // actually used (may differ from the order's own).
            'resendAttempts' => fn ($query) => $query->with('package:id,name')->latest(),
        ]));
    }

    /**
     * ORD-7 / ADR-014: the manual counterpart to FulfillOrderJob's own
     * automated retries — for when those are exhausted (or the
     * failure was a genuine data/validation issue an admin fixed) and
     * an operator wants to try again. Queued, same as the webhook
     * path, so this request never blocks on a live Gamevion call
     * either. OrderStatusService::startDelivery() already allows a
     * Failed → Processing transition (that's how the very first retry
     * attempt inside fulfill() itself works) — this only adds an
     * admin-facing trigger for it, guarded here so a request against a
     * non-Failed order gets an immediate, clear rejection instead of a
     * job that's silently a no-op.
     */
    public function retryDelivery(Order $order): JsonResponse
    {
        if ($order->delivery_status !== DeliveryStatus::Failed) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed delivery can be retried.'],
            ]);
        }

        FulfillOrderJob::dispatch($order);

        return response()->json(['message' => 'Delivery retry queued.']);
    }

    /**
     * ADR-017: the richer, package-swap counterpart to retryDelivery()
     * above. Same-request guards here exist purely for fast, clear
     * admin feedback (a bad request never even reaches the queue) —
     * OrderResendService re-checks every one of them itself at actual
     * attempt time (ResendOrderDeliveryJob), since state can change
     * between "admin clicked the button" and "the job actually ran".
     */
    public function resend(ResendOrderDeliveryRequest $request, Order $order): JsonResponse
    {
        if ($order->delivery_status !== DeliveryStatus::Failed) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed delivery can be resent.'],
            ]);
        }

        $targetPackage = Package::query()->findOrFail($request->validated('package_id'));

        if ($targetPackage->game_id !== $order->game_id) {
            throw ValidationException::withMessages([
                'package_id' => ['The selected package must belong to the same game as this order.'],
            ]);
        }

        ResendOrderDeliveryJob::dispatch(
            $order,
            $targetPackage->id,
            $request->validated('note'),
            $request->user()->name,
        );

        return response()->json(['message' => 'Resend queued.']);
    }
}
