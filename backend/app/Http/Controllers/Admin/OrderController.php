<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmOrderDeliveryFailedRequest;
use App\Http\Requests\MarkOrderDeliveredRequest;
use App\Http\Requests\ResendOrderDeliveryRequest;
use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Package;
use App\Models\ResellerBotOrderNotification;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\SupplierDeliveryCheckService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\OpenWa\OpenWaClient;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Pricing\PricingBasis;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Services\Reseller\Webhook\ResellerWebhookDispatcher;
use App\Services\Reseller\Webhook\ResellerWebhookEvent;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Support\ManualCheckCooldown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // `affiliate`/`walletReseller` — which brand's storefront (or,
        // for a wallet order, which Reseller API/Bot account) this order
        // came from. Founder-requested visibility: the list previously
        // loaded neither, so there was no way to tell at a glance.
        $query = Order::query()->where('is_test', false)->with([
            'game:id,name', 'package:id,name',
            'affiliate:id,business_name', 'walletReseller:id,business_name',
        ])
            // ADR-102 decision 12 — cheap correlated-subquery booleans
            // (never an N+1 per row) powering the list's compensation
            // badges: 🎫 Used Voucher / 🎟️ Voucher Issued. `wallet_refunded`'s
            // own badge is computed separately below (no plain
            // relation exists for it — see the batched query there).
            ->withExists([
                'voucher as has_compensation_voucher',
                'paidWithVoucher as has_used_voucher',
                // ADR-024 addendum (2026-09-17, restore-only) — the 4th
                // badge, mirroring show()'s own has_voucher_restored.
                'voucherRedemption as has_voucher_restored' => fn ($query) => $query->where('status', 'restored'),
            ]);

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

        $page = $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString();

        // ADR-102 decision 12 — one batched query for the 💰 Refunded to
        // Wallet badge, not a per-row lookup: no plain Eloquent relation
        // exists from Order to its wallet-refund LedgerEntry (see
        // Order::walletRefundLedgerEntry()'s own correlated-query
        // reasoning), and this page's order ids are already known after
        // pagination, so a single whereIn() covers the whole page.
        $walletRefundedOrderIds = LedgerEntry::query()
            ->where('type', 'wallet_refund')
            ->where('reference_type', 'order')
            ->whereIn('reference_id', $page->getCollection()->pluck('id'))
            ->pluck('reference_id')
            ->all();

        $page->getCollection()->each(
            fn (Order $order) => $order->setAttribute('has_wallet_refund', in_array($order->id, $walletRefundedOrderIds, true)),
        );

        return response()->json($page);
    }

    /**
     * ADR-092: the six Orders KPI-card counts in one grouped query,
     * deliberately not piggybacked onto index()'s paginated response —
     * that query already varies per search/filter/page on every
     * keystroke, and a card's count shouldn't be recomputed by typing.
     * "processing" combines delivery_status processing + pending_delivery
     * (decision 2 — both read as "being worked on" from an admin's-eye
     * view; the two underlying filter pills stay separately clickable).
     * "today" is orthogonal to delivery status, cutting across every
     * bucket by created_at. Same is_test=false scope as index().
     */
    public function summary(): JsonResponse
    {
        $row = Order::query()
            ->where('is_test', false)
            ->selectRaw(
                'SUM(CASE WHEN payment_status = ? AND delivery_status = ? THEN 1 ELSE 0 END) as need_action,
                 SUM(CASE WHEN delivery_status = ? THEN 1 ELSE 0 END) as needs_review,
                 SUM(CASE WHEN delivery_status IN (?, ?) THEN 1 ELSE 0 END) as processing,
                 SUM(CASE WHEN delivery_status = ? THEN 1 ELSE 0 END) as completed,
                 SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as today,
                 COUNT(*) as all_orders',
                [
                    PaymentStatus::Paid->value, DeliveryStatus::Failed->value,
                    DeliveryStatus::NeedsReview->value,
                    DeliveryStatus::Processing->value, DeliveryStatus::Pending->value,
                    DeliveryStatus::Delivered->value,
                    now()->startOfDay(), now()->endOfDay(),
                ],
            )
            ->first();

        return response()->json([
            'need_action' => (int) $row->need_action,
            'needs_review' => (int) $row->needs_review,
            'processing' => (int) $row->processing,
            'completed' => (int) $row->completed,
            'today' => (int) $row->today,
            'all' => (int) $row->all_orders,
        ]);
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

        return $this->orderDetailResponse($order);
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
    public function retryDelivery(Request $request, Order $order): JsonResponse
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

        // ADR-024 decision #8 / ADR-102 decision 1: once this order is
        // already compensated — a voucher issued (the admin's own
        // "give up" decision — VoucherController::storeFromOrder()) OR
        // a reseller-wallet refund already paid out — Resend Delivery
        // must never succeed again: a later successful resend would
        // double-compensate the customer (goods delivered *and*
        // compensation already in hand). `isAlreadyCompensated()` is
        // the single source of truth every one of these guards reads,
        // closing a real gap the voucher-only check never covered
        // (order `PG-CGDZOLEHAIR8`, wallet-refunded, `failed`, resend
        // was never blocked).
        if ($order->isAlreadyCompensated()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has already been compensated (voucher issued/restored or wallet refunded) — it cannot be resent.'],
            ]);
        }

        $this->guardResendUnsafeOverride($request, $order);

        FulfillOrderJob::dispatch($order);

        return response()->json(['message' => 'Delivery retry queued.']);
    }

    /**
     * ADR-102 decision 3 — an admin can still click Resend/Retry on a
     * scoped-unsafe order (Order::resendUnsafeToOverride()), but only
     * with a mandatory free-text reason, logged: this is money-critical
     * (the resubmit really is unlikely to change anything), so an
     * override needs an audit trail, not a silent bypass. Shared by
     * both retryDelivery() and resend() so the two never drift on this
     * rule.
     *
     * ADR-105 decision 4 — extended with a second, independent trigger:
     * a package swap (never a same-package retry, hence `$targetPackage`
     * is null from retryDelivery()) whose live cost now exceeds what
     * the customer already paid. This is purely a fast, same-request
     * echo of the check `OrderResendService::resend()` makes for real at
     * attempt time (same reasoning as this whole method's own doc
     * comment above it in resend() — a controller-side check can go
     * stale, the job re-checks everything itself) — its only job is
     * giving the admin an immediate, clear rejection instead of a
     * queued job that silently fails later.
     */
    private function guardResendUnsafeOverride(Request $request, Order $order, ?Package $targetPackage = null): void
    {
        $unsafeReference = $order->resendUnsafeToOverride();

        $wouldSellBelowCost = $targetPackage !== null
            && $order->pricing_basis !== PricingBasis::Member
            && $order->wholesale_markup_pct === null
            && $targetPackage->cost_price > $order->standard_selling_price;

        if (! $unsafeReference && ! $wouldSellBelowCost) {
            return;
        }

        $reason = trim((string) $request->input('override_reason', ''));

        if ($reason === '') {
            $message = $wouldSellBelowCost
                ? sprintf(
                    "This package's live cost (RM%s) now exceeds what the customer already paid (RM%s) — provide a reason to resend anyway and accept the loss.",
                    number_format($targetPackage->cost_price / 100, 2),
                    number_format($order->standard_selling_price / 100, 2),
                )
                : 'This order already has a final, confirmed result for its reference — a package swap does not escape this either. Provide a reason to override and resend anyway.';

            throw ValidationException::withMessages(['override_reason' => [$message]]);
        }

        Log::warning('Admin overrode a resend guard', [
            'order_number' => $order->order_number,
            'admin' => $request->user()->name,
            'override_reason' => $reason,
            'guard' => $unsafeReference ? 'resend_unsafe_reference' : 'sell_below_cost',
        ]);
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

        // ADR-024 decision #8 / ADR-102 decision 1 — see retryDelivery()'s
        // identical guard for the full reasoning.
        if ($order->isAlreadyCompensated()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has already been compensated (voucher issued/restored or wallet refunded) — it cannot be resent.'],
            ]);
        }

        $targetPackage = Package::query()->findOrFail($request->validated('package_id'));

        if ($targetPackage->game_id !== $order->game_id) {
            throw ValidationException::withMessages([
                'package_id' => ['The selected package must belong to the same game as this order.'],
            ]);
        }

        // ADR-105 decision 4: resolved above, not before, since this
        // guard now also needs $targetPackage for its sell-below-cost
        // check.
        $this->guardResendUnsafeOverride($request, $order, $targetPackage);

        ResendOrderDeliveryJob::dispatch(
            $order,
            $targetPackage->id,
            $request->validated('note'),
            $request->user()->name,
            // ADR-102 decision 10 — the optional Player ID/Server ID correction.
            $request->validated('player_id'),
            $request->validated('server_id'),
            trim((string) $request->input('override_reason', '')) ?: null,
        );

        return response()->json(['message' => 'Resend queued.']);
    }

    /**
     * ADR-096 decision 5 — a deliberate, scoped exception to ADR-014's
     * "never call the supplier synchronously" rule: a single short
     * read-only status-check call, admin-initiated, so the raw response
     * can be shown immediately rather than via a queued job's delayed
     * result. Shares SupplierDeliveryCheckService with the scheduled
     * CheckSupplierDeliveryJob (decision 7) — same finalize path, same
     * combo per-leg loop, never a second copy of that logic.
     */
    public function checkSupplier(Order $order, SupplierAdapterFactory $supplierAdapters, OrderFulfillmentService $fulfillment, ManualCheckCooldown $cooldown): JsonResponse
    {
        if ($order->is_test) {
            abort(404);
        }

        if ($order->delivery_status !== DeliveryStatus::Pending) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a Pending delivery can be checked from the supplier.'],
            ]);
        }

        $cooldownKey = "manual-check:supplier:{$order->id}";
        $remaining = $cooldown->remainingSeconds($cooldownKey);

        if ($remaining > 0) {
            return response()->json(['message' => 'Checked too recently — please wait before checking again.', 'retry_after_seconds' => $remaining], 422);
        }

        // ADR-032 decision 6 / ADR-096 decision 8 — a combo order has no
        // supplier of its own (decision 3); its cooldown (and any
        // future per-supplier override) is keyed on the first
        // component's supplier, mirroring ReconcilePendingDeliveriesCommand's
        // own checkStalePending() resolution.
        $supplier = $order->package?->is_combo
            ? $order->package->components->first()?->supplier
            : $order->supplier;
        $cooldownSeconds = $supplier?->api_config['manual_check_cooldown_seconds'] ?? config('services.manual_check.cooldown_seconds');

        try {
            $result = (new SupplierDeliveryCheckService($supplierAdapters, $fulfillment))->check($order);
        } catch (\Throwable $e) {
            $cooldown->start($cooldownKey, $cooldownSeconds);
            Log::error('Manual supplier check failed', ['order_number' => $order->order_number, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Supplier check failed: '.$e->getMessage()], 502);
        }

        $cooldown->start($cooldownKey, $cooldownSeconds);

        $fresh = $order->fresh();

        return response()->json([
            'result' => $result,
            'delivery_status' => $fresh->delivery_status->value,
            'delivered_at' => $fresh->delivered_at?->toISOString(),
            'supplier_ref' => $fresh->supplier_ref,
        ]);
    }

    /**
     * ADR-096 decision 5/7 — the payment-side counterpart to
     * checkSupplier() above. Shares PaymentReconciliationService with
     * the scheduled ReconcilePendingPaymentsCommand.
     */
    public function checkGateway(Order $order, PaymentReconciliationService $reconciliation, ManualCheckCooldown $cooldown): JsonResponse
    {
        if ($order->is_test) {
            abort(404);
        }

        if ($order->payment_status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'payment_status' => ['Only an order with a Pending payment can be checked from the gateway.'],
            ]);
        }

        $cooldownKey = "manual-check:gateway:{$order->id}";
        $remaining = $cooldown->remainingSeconds($cooldownKey);

        if ($remaining > 0) {
            return response()->json(['message' => 'Checked too recently — please wait before checking again.', 'retry_after_seconds' => $remaining], 422);
        }

        // ADR-096 decision 8 — no per-gateway cooldown override exists
        // (unlike the supplier side); the global default applies to
        // every gateway uniformly.
        $cooldownSeconds = config('services.manual_check.cooldown_seconds');

        try {
            $result = $reconciliation->reconcileOrder($order);
        } catch (\Throwable $e) {
            $cooldown->start($cooldownKey, $cooldownSeconds);
            Log::error('Manual gateway check failed', ['order_number' => $order->order_number, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Gateway check failed: '.$e->getMessage()], 502);
        }

        $cooldown->start($cooldownKey, $cooldownSeconds);

        $fresh = $order->fresh();

        return response()->json([
            'result' => $result,
            'payment_status' => $fresh->payment_status->value,
            'paid_at' => $fresh->paid_at?->toISOString(),
        ]);
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

        // ADR-024 decision #8 / ADR-102 decision 1 — see retryDelivery()'s
        // identical guard for the full reasoning. Structurally shouldn't
        // be reachable (Issue Voucher is blocked from needs_review, ADR-026
        // decision 4c), kept as the same defensive check its siblings carry.
        if ($order->isAlreadyCompensated()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has already been compensated (voucher issued/restored or wallet refunded) — it cannot be marked delivered.'],
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
        return $this->orderDetailResponse($result);
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the
     * NeedsReview exit decision 4c's own rationale always assumed
     * existed but was never built. Lands on plain Failed; Issue Voucher
     * (VoucherController::storeFromOrder(), Failed-only gate, unchanged)
     * is a deliberately separate admin-triggered second step.
     * Explicitly excludes a genuine partial-combo-delivery needs_review
     * order (ADR-094 decision 9 already has its own custom-amount
     * voucher path there — some legs DID deliver, so confirming the
     * WHOLE order "failed" would be wrong).
     */
    public function confirmFailed(ConfirmOrderDeliveryFailedRequest $request, Order $order, OrderFulfillmentService $fulfillment): JsonResponse
    {
        // ADR-018 decision #2: same reasoning as retryDelivery()/resend() above.
        if ($order->is_test) {
            abort(404);
        }

        if ($order->delivery_status !== DeliveryStatus::NeedsReview) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order in needs-review can be confirmed failed.'],
            ]);
        }

        if ($order->isPartialComboDelivery()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has a partial delivery — issue a custom-amount voucher for the failed leg(s) instead of confirming the whole order failed.'],
            ]);
        }

        // ADR-024 decision #8 / ADR-102 decision 1 — see retryDelivery()'s
        // identical guard for the full reasoning. Structurally shouldn't
        // be reachable, kept as the same defensive check its siblings carry.
        if ($order->isAlreadyCompensated()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has already been compensated (voucher issued/restored or wallet refunded).'],
            ]);
        }

        $result = $fulfillment->confirmDeliveryFailed(
            $order,
            $request->validated('note'),
            $request->user()->name,
        );

        return $this->orderDetailResponse($result);
    }

    /**
     * ADR-073 decision 7: the wallet-order counterpart to
     * VoucherController::storeFromOrder() — for a `wallet_reseller_id`-
     * owned order, this REPLACES Issue Voucher entirely in that order's
     * detail screen (never shown alongside it), since Voucher's
     * email-keyed mechanism has no meaning for a B2B wallet account and
     * a dual option only invites the wrong one being clicked. Not a
     * reopening of ADR-004's "no cash refund" policy — no cash ever
     * leaves the platform, this is an internal-credit reversal back
     * into a balance we fully control.
     */
    public function refundToWallet(Request $request, Order $order, LedgerService $ledger, OpenWaClient $openWa, ResellerWebhookDispatcher $webhooks): JsonResponse
    {
        if ($order->is_test) {
            abort(404);
        }

        if ($order->wallet_reseller_id === null) {
            throw ValidationException::withMessages([
                'order' => ['This order was not placed against a Reseller wallet.'],
            ]);
        }

        if ($order->delivery_status !== DeliveryStatus::Failed) {
            throw ValidationException::withMessages([
                'order' => ['A wallet refund can only be issued for an order with a failed delivery.'],
            ]);
        }

        // ADR-102 finding (mid-build, not in the original grill):
        // `ledger_entries` has no unique index on the
        // (type, reference_type, reference_id) tuple — unlike Voucher's
        // real `vouchers.order_id` unique index, there was never an
        // actual structural backstop here, only this pre-check. Two
        // concurrent refundToWallet() requests for the same order could
        // both pass it and both credit. Fixed the same way as
        // VoucherController::storeFromOrder() — an `Order::lockForUpdate()`
        // inside the transaction, contending on the exact same lock
        // `fulfill()`/`storeFromOrder()`/`markDeliveredManually()`/
        // `confirmDeliveryFailed()` already all acquire, so this
        // check-then-credit can no longer race against itself or
        // against a resend/voucher-issue on the same order.
        DB::transaction(function () use ($order, $ledger) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->isAlreadyRefundedToWallet()) {
                throw ValidationException::withMessages([
                    'order' => ['This order has already been refunded to the reseller\'s wallet.'],
                ]);
            }

            $ledger->credit(
                LedgerOwnerType::ResellerWallet,
                $locked->wallet_reseller_id,
                $locked->final_amount,
                'wallet_refund',
                referenceType: 'order',
                referenceId: $locked->id,
            );
        });

        Log::info('Order refunded to reseller wallet', [
            'order_id' => $order->id,
            'wallet_reseller_id' => $order->wallet_reseller_id,
            'amount_sen' => $order->final_amount,
            'admin_user_id' => $request->user()?->id,
        ]);

        // ADR-076 decision 6: this action deliberately never touches
        // payment_status/delivery_status, so it's invisible to
        // SendResellerBotOrderNotification's OrderStatusUpdated listener
        // — the only explicit, non-event-driven notify call in that
        // design. Guarded by refund_notified_at so a repeat request
        // (already rejected above by alreadyRefundedToWallet(), but
        // defensive here too) can never double-send.
        $notification = ResellerBotOrderNotification::query()
            ->where('order_id', $order->id)
            ->whereNull('refund_notified_at')
            ->first();

        if ($notification !== null) {
            $openWa->sendText($notification->whatsapp_group_id, ResellerBotReplyFormatter::refundNotice($order));
            $notification->update(['refund_notified_at' => now()]);
        }

        // ADR-084 PR-3 decision 4: the API channel's counterpart to the
        // Bot refund notice above — dispatched here, not via
        // OrderStatusUpdated, because this action deliberately never
        // touches delivery_status. No-op unless the reseller has an
        // active webhook; the (order, 'order.refunded') uniqueness keeps a
        // repeat request (already rejected above) from double-sending.
        $webhooks->dispatch($order->fresh(), ResellerWebhookEvent::OrderRefunded);

        return $this->orderDetailResponse($order->fresh());
    }

    /**
     * The one shape every order-detail response returns — show() and
     * every mutating action that hands back the updated order
     * (markDelivered, refundToWallet) all funnel through here so they
     * can never drift apart on which relations/fields the frontend's
     * `OrderDetail` type expects (the exact bug markDelivered()'s own
     * doc comment already records once).
     */
    private function orderDetailResponse(Order $order): JsonResponse
    {
        $order->load([
            'game', 'package', 'supplier', 'affiliate', 'voucher', 'voucherRedemption',
            // ADR-102 decision 11 (a) — the voucher this order was PAID
            // WITH, distinct from `voucher` above (the compensation
            // voucher issued because this order failed). Powers the
            // Order Detail "Voucher Used to Pay" refund-information card.
            'paidWithVoucher',
            // ADR-073 decision 7: which Reseller (wallet) account placed
            // this order, if any — the admin detail screen swaps "Issue
            // Voucher" for "Refund to Wallet" when this is set.
            'walletReseller:id,business_name',
            // ADR-027 Phase 6: the member (if any) this order priced
            // against — email + tier name, so admin can see who and
            // which plan without a separate lookup. Note this
            // membership's own email can genuinely differ from the
            // order's own customer_email (identity comes from the
            // session token, not the checkout contact form).
            'membership.membershipPlan',
            // ADR-017 decision #4: "Delivery Logs" — every resend
            // attempt, most recent first, alongside the package it
            // actually used (may differ from the order's own).
            'resendAttempts' => fn ($query) => $query->with('package:id,name')->latest(),
            // ADR-094 decision 12 (2026-09-15 Phase 4): empty for every
            // ordinary single-supplier order — populated only for a
            // combo order, one row per real outbound supplier call,
            // for the detail screen's leg-breakdown table.
            // standard_selling_price is needed here, not just for
            // display — Order::suggestedPartialVoucherAmount() sums it
            // straight off this already-eager-loaded relation.
            // supplier_package_ref (2026-09-16 addendum): the leg
            // breakdown's own "Supplier Ref" column is the leg's
            // supplier_reference (the supplier's transaction/response
            // id), which doesn't tell admin WHICH product SKU was
            // submitted for that leg — real gap when two components
            // share a denomination across suppliers.
            'deliveryLegs.componentPackage:id,name,denomination,standard_selling_price,supplier_package_ref',
            'deliveryLegs.supplier:id,name',
        ]);

        return response()->json([
            ...$order->toArray(),
            // ADR-073 decision 7: computed, not a stored column — lets
            // the frontend disable/hide the Refund to Wallet button on
            // a fresh page load too, not just right after a successful
            // action in the same session.
            'wallet_refunded' => $order->isAlreadyRefundedToWallet(),
            // ADR-102 decision 11 (b) — the underlying LedgerEntry's own
            // amount/created_at, not just the boolean above: the "Wallet
            // Refund" card needs to show more than a yes/no.
            'wallet_refund' => ($entry = $order->walletRefundLedgerEntry()) !== null ? [
                'amount' => $entry->amount,
                'created_at' => $entry->created_at?->toISOString(),
            ] : null,
            // ADR-102 decision 12 — the same three badge booleans
            // index() computes via cheap correlated subqueries, mirrored
            // here from already-loaded relations/values (free — no
            // extra query) so OrderDetail (which extends the list's
            // shape) never has to special-case a field only present on
            // one of the two endpoints.
            'has_used_voucher' => $order->paidWithVoucher !== null,
            'has_compensation_voucher' => $order->voucher !== null,
            'has_wallet_refund' => $entry !== null,
            // ADR-024 addendum (2026-09-17, restore-only) — the 4th
            // compensation badge: true once this order's own redemption
            // is 'restored', independent of has_compensation_voucher
            // (a full-cover-by-voucher order restores but mints no new
            // voucher, so that one alone would stay false forever).
            'has_voucher_restored' => $order->voucherRedemption?->status === 'restored',
            // ADR-094 decision 9: gates the admin panel's Issue Voucher
            // button for the one needs_review case that's actually a
            // genuine partial delivery, with a starting-point amount
            // (admin-adjustable, never trusted as-is server-side —
            // VoucherController::storeFromOrder() re-derives its own
            // cap independently).
            'partial_combo_delivery' => $order->isPartialComboDelivery(),
            // ADR-026 addendum (2026-09-16), renamed by ADR-102 decision
            // 3/5 — drives the "Resending is unlikely to change this
            // outcome" warning next to the Resend Delivery button,
            // computed server-side so the frontend never hand-copies
            // Digiflazz's own rc table.
            'delivery_retry_unsafe_with_same_reference' => $order->deliveryRetryUnsafeWithSameReference(),
            // ADR-102 decision 3 — the SCOPED rule (non-combo: NeedsReview
            // only; combo: regardless of Failed/NeedsReview) that
            // actually disables the Resend/Retry button and requires a
            // logged override reason to proceed anyway.
            'resend_unsafe_to_override' => $order->resendUnsafeToOverride(),
            'suggested_voucher_amount' => $order->suggestedPartialVoucherAmount(),
            // ADR-107 decision 3 — true only once a combo order actually
            // delivered with a reconciled negative platform_profit
            // (never blocks delivery; this is the after-the-fact
            // visibility signal instead). Drives the Order Detail
            // "Combo Profit Adjusted" info card.
            'combo_profit_reconciled_negative' => $order->hasNegativeComboProfit(),
        ]);
    }
}
