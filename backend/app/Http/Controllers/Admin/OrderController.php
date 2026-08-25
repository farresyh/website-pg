<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarkOrderDeliveredRequest;
use App\Http\Requests\ResendOrderDeliveryRequest;
use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\Order;
use App\Models\Package;
use App\Models\Voucher;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ORD-1..7 — read-only list + detail, plus retryDelivery (ORD-7's
 * resolve action — ADR-014) and resend (ADR-017's richer, package-swap
 * counterpart). Voucher issuance itself is VoucherController::storeFromOrder()
 * (its own controller) — show() only eager-loads the `voucher` relation
 * so the Admin Panel knows whether one's already been issued for this
 * order. Export (ORD-5) remains out of scope here. See docs/prd.md §14.
 */
class OrderController extends Controller
{
    /**
     * `status` (ORD-2): need_action (paid but delivery failed —
     * customer's money is in, credits never arrived), needs_review
     * (ADR-026/ORD-10 — an ambiguous delivery outcome, distinct from
     * need_action: "Issue Voucher" is deliberately not available here,
     * only "Mark as Delivered" or "Resend Delivery"), pending_delivery
     * (ADR-032 — an async supplier accepted the order but hasn't
     * confirmed the final outcome yet; resolves itself via webhook or
     * ReconcilePendingDeliveriesCommand's own poll, no admin action
     * available here), processing (delivery attempt in flight),
     * completed (delivered), awaiting_payment (still pending 30+
     * minutes after checkout — ADR-021/PAY-3's own visibility gap for
     * a webhook that never arrived; ReconcilePendingPaymentsCommand
     * acts on the same window), today, or omitted for all.
     */
    public function index(Request $request): JsonResponse
    {
        // ADR-018 decision #2: permanent, unconditional — never an
        // admin-toggleable filter. This screen must be 100%
        // trustworthy at a glance (e.g. the "Need Action" count); a
        // sandbox order can only ever be seen/acted on via
        // Middleware\SandboxOrderController's own is_test=true scope.
        $query = Order::query()->where('is_test', false)->with(['game:id,name', 'package:id,name']);

        match ($request->query('status')) {
            'need_action' => $query
                ->where('payment_status', PaymentStatus::Paid->value)
                ->where('delivery_status', DeliveryStatus::Failed->value),
            'needs_review' => $query->where('delivery_status', DeliveryStatus::NeedsReview->value),
            'pending_delivery' => $query->where('delivery_status', DeliveryStatus::Pending->value),
            'processing' => $query->where('delivery_status', DeliveryStatus::Processing->value),
            'completed' => $query->where('delivery_status', DeliveryStatus::Delivered->value),
            'awaiting_payment' => $query
                ->where('payment_status', PaymentStatus::Pending->value)
                ->where('created_at', '<=', now()->subMinutes(30)),
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
        // ADR-018 decision #2: route-model binding alone can't scope by
        // is_test (it only knows the primary key) — this stops a
        // sandbox order id from ever being read through this
        // real-money-facing controller.
        if ($order->is_test) {
            abort(404);
        }

        return response()->json($order->load([
            'game', 'package', 'supplier', 'reseller', 'voucher',
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
     * non-Failed/non-needs_review order gets an immediate, clear
     * rejection instead of a job that's silently a no-op. needs_review
     * added by ADR-026 decision 4b — same retry mechanism resolves it.
     */
    public function retryDelivery(Order $order): JsonResponse
    {
        // ADR-018 decision #2: a sandbox order id must never reach the
        // real, queued GamevionAdapter path — it exists only for the
        // sandbox's own synchronous FakeSupplierAdapter flow
        // (Middleware\SandboxOrderController::resend()).
        if ($order->is_test) {
            abort(404);
        }

        if (! in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed or needs-review delivery can be retried.'],
            ]);
        }

        // ADR-024 decision #8: once a voucher has been issued for this
        // order (the admin's own "give up" decision — VoucherController
        // ::storeFromOrder()), Resend Delivery must never succeed again
        // — a later successful resend would double-compensate the
        // customer (goods delivered *and* a voucher already in hand).
        if (Voucher::query()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['A voucher has already been issued for this order — it cannot be resent.'],
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
        // ADR-018 decision #2: same reasoning as retryDelivery() above.
        if ($order->is_test) {
            abort(404);
        }

        if (! in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed or needs-review delivery can be resent.'],
            ]);
        }

        // ADR-024 decision #8 — see retryDelivery()'s identical guard
        // for the full reasoning.
        if (Voucher::query()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['A voucher has already been issued for this order — it cannot be resent.'],
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

    /**
     * ADR-026 decision 4a — the one exit from needs_review that isn't a
     * retry. Synchronous (not queued): this makes no supplier call at
     * all, only a DB write + ledger credit, same as
     * OrderFulfillmentService::fulfill()'s own success branch.
     */
    public function markDelivered(MarkOrderDeliveredRequest $request, Order $order, OrderFulfillmentService $fulfillment): JsonResponse
    {
        // ADR-018 decision #2: same reasoning as retryDelivery()/resend() above.
        if ($order->is_test) {
            abort(404);
        }

        if ($order->delivery_status !== DeliveryStatus::NeedsReview) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order in needs-review can be manually marked delivered.'],
            ]);
        }

        // ADR-024 decision #8 — see retryDelivery()'s identical guard
        // for the full reasoning. Structurally shouldn't be reachable
        // (Issue Voucher is blocked from needs_review, ADR-026 decision
        // 4c), kept as the same defensive check its siblings carry.
        if (Voucher::query()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['A voucher has already been issued for this order — it cannot be marked delivered.'],
            ]);
        }

        $result = $fulfillment->markDeliveredManually(
            $order,
            $request->validated('supplier_ref'),
            $request->validated('note'),
            $request->user()->name,
        );

        // Same relations as show() — the frontend's OrderDetail type
        // requires game/package/voucher/resend_attempts, and
        // markDeliveredManually() returns a bare $locked->fresh() with
        // none of them loaded. Found live: without this, the admin
        // panel's own setSelected(updated) crashes rendering
        // DeliveryLogsTable on the now-undefined resend_attempts.
        return response()->json($result->load([
            'game', 'package', 'supplier', 'reseller', 'voucher',
            'resendAttempts' => fn ($query) => $query->with('package:id,name')->latest(),
        ]));
    }
}
