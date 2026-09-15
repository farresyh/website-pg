<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates checkout COMPLETION — submitting a paid order to the
 * supplier and recording the outcome. Assumes payment_status is
 * already genuinely Paid (set by whatever verifies and processes the
 * payment-gateway webhook — that verification/idempotency check
 * happens before this is ever called, not inside it).
 *
 * ADR-026 (ORD-10): a duplicate_reference response — Gamevion's own
 * idempotency signal that a prior attempt for this reference_number
 * already reached them — routes straight to delivery_status=needs_review
 * instead of a plain failed, regardless of whether this call is a
 * fresh first attempt or a retry (ReconcilePendingDeliveriesCommand's
 * own re-dispatch included). No automated resolution is attempted
 * beyond that: Gamevion's check-status endpoint needs its own invoice
 * number, which duplicate_reference never supplies, and neither its
 * API nor its dashboard support a reference-number lookup (confirmed
 * live, see ADR-026's Context) — only an admin manually cross-checking
 * Gamevion's dashboard can resolve it (markDeliveredManually() below).
 */
final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ReferenceNumberService $referenceNumbers,
        private readonly SupplierAdapterFactory $supplierAdapters,
        private readonly LedgerService $ledger,
        private readonly VoucherService $vouchers,
        private readonly SupplierFundingService $supplierFunding,
    ) {}

    /**
     * A webhook sender (Xendit's own docs call this out as expected
     * behavior) can deliver the same payment-confirmed event twice —
     * PAY-2 requires that this never causes duplicate supplier orders
     * or duplicate credit delivery. The Order row is re-read here
     * with lockForUpdate() inside a transaction (same pattern as
     * LedgerService::withdraw()/VoucherService::redeem()) so a second,
     * genuinely concurrent call blocks until the first commits, then
     * observes the already-advanced delivery_status and is rejected by
     * OrderStatusService's guard — never generates a second
     * reference_number or submits a second supplier order for the
     * same Order.
     */
    public function fulfill(Order $order): Order
    {
        // ADR-094 decision 7: a combo order (Order.package.is_combo)
        // diverts to its own leg-loop sub-flow before any of the
        // single-ref guards below — it has no supplier_product_ref/
        // supplier_id of its own to check (ADR-094 decision 3).
        if ($order->package?->is_combo) {
            return $this->fulfillCombo($order);
        }

        // ADR-083 decision 3 — captured here, written to the supplier
        // funding ledger only AFTER the transaction below commits (see
        // the bottom of this method). Stays null unless the Success
        // branch actually runs and the adapter reported a price.
        $drawdownPrice = null;

        $delivered = DB::transaction(function () use ($order, &$drawdownPrice) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ORD-11's central guard: delivery may only start once
            // payment is genuinely paid. OrderStatusService throws
            // otherwise — the single most direct path to giving away
            // free game credits without confirmed payment, never
            // bypassed here.
            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);

            if ($locked->supplier_product_ref === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_product_ref set — cannot submit to supplier",
                );
            }

            // ADR-031: one Package = one supplier, fixed at
            // package-curation/checkout time — resolved fresh on every
            // call (including a retry) rather than cached on this
            // service, since a resend (OrderResendService) can change
            // which supplier an order targets between attempts.
            if ($locked->supplier_id === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_id set — cannot resolve a SupplierAdapter",
                );
            }

            $adapter = $this->supplierAdapters->make($locked->supplier->slug);

            // ORD-8: generated once, reused on every retry of this
            // order — resolve() returns the existing value unchanged
            // if this is a retry after a prior failure.
            $referenceNumber = $this->referenceNumbers->resolve($locked->reference_number);

            // ADR-014: extends whatever context the caller already set
            // (order_number, from the webhook/job) with the ORD-8 key
            // Gamevion itself is called with — the two together are
            // what a support conversation ("customer's order didn't
            // arrive") and a raw Gamevion dashboard lookup have in
            // common.
            Log::withContext(['reference_number' => $referenceNumber]);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            $result = $adapter->createOrder(new SupplierOrderRequest(
                productRef: $locked->supplier_product_ref,
                referenceNumber: $referenceNumber,
                playerId: $locked->player_id,
                serverId: $locked->server_id,
                customerPhone: $locked->customer_phone,
                orderId: $locked->id,
            ));

            // ADR-032: branches on the adapter's normalized outcome,
            // never on raw success/failure alone — a Pending response
            // (async supplier, e.g. Digiflazz) is neither a clean
            // delivery nor a rejection, and must never be mistaken for
            // either.
            if ($result->outcome === SupplierOutcome::Pending) {
                $locked->update([
                    'delivery_status' => $this->orderStatus->markPending($processingStatus)->value,
                    'supplier_response' => $result->data,
                ]);

                Log::info('Delivery pending — awaiting async supplier confirmation', [
                    'supplier_response' => $result->data,
                ]);

                return $locked->fresh();
            }

            if ($result->outcome === SupplierOutcome::Failure) {
                // ADR-026: duplicate_reference is structurally different
                // from every other failure — it's evidence an order for
                // this reference_number already reached Gamevion, not
                // evidence it was rejected. Routes to needs_review, never
                // failed, so it can never be mistaken for a plain
                // resolvable failure (and, critically, never reaches
                // VoucherController::storeFromOrder()'s Failed-only gate).
                $isDuplicateReference = $result->errorCode === 'duplicate_reference';

                $locked->update([
                    'delivery_status' => $isDuplicateReference
                        ? $this->orderStatus->markNeedsReview($processingStatus)->value
                        : $this->orderStatus->markDeliveryFailed($processingStatus)->value,
                    'supplier_response' => [
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ],
                ]);

                // ADR-014: the one line a file-log admin actually needs
                // to notice without watching the Admin Orders screen —
                // a business-level failure (this branch) never throws,
                // so without this line it would be silent until someone
                // looks. Grep by reference_number/order_number to find
                // the matching webhook/job lines for full context.
                Log::warning($isDuplicateReference ? 'Delivery ambiguous — needs manual review' : 'Delivery failed', [
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'supplier_ref' => $result->data['supplier_ref'] ?? null,
                'supplier_response' => $result->data,
                'delivery_status' => $this->orderStatus->markDelivered($processingStatus)->value,
                'delivered_at' => now(),
            ]);

            $this->creditProfit($locked);

            // ADR-024 decision #6 — both payment and delivery succeeded,
            // the third and final outcome of the voucher-redemption
            // three-outcome model: any reserved redemption this order
            // made is now permanent, never restored. No-op if this
            // order never used a voucher.
            $this->vouchers->commit($locked->id);

            if (isset($result->data['price'])) {
                $drawdownPrice = (float) $result->data['price'];
            }

            return $locked->fresh();
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($delivered, $drawdownPrice);
        }

        return $delivered;
    }

    /**
     * ADR-094 decision 7: the combo counterpart to fulfill()'s
     * single-supplier-call path. Three short, separately-committed
     * steps rather than one transaction spanning the whole order —
     * holding lockForUpdate() across up to 3 outbound HTTP round-trips
     * (decision 20's leg cap) would be the exact lock-contention class
     * ADR-077 already fixed once in production:
     *
     *  1. Advance the order to Processing and seed `order_delivery_legs`
     *     from `package->components` on the first attempt only (a retry
     *     finds its legs already there and reuses them — decision 7's
     *     idempotent-by-construction leg loop).
     *  2. Attempt every not-yet-terminal leg exactly once, each its own
     *     short lock+transaction+one-HTTP-call cycle, in `leg_number`
     *     order.
     *  3. Aggregate the legs' resulting statuses into the order's own
     *     delivery_status (resolveComboOutcome()).
     */
    private function fulfillCombo(Order $order): Order
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);
            $referenceNumber = $this->referenceNumbers->resolve($locked->reference_number);

            Log::withContext(['reference_number' => $referenceNumber]);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            if (! OrderDeliveryLeg::query()->where('order_id', $locked->id)->exists()) {
                $this->seedDeliveryLegs($locked);
            }
        });

        $order = $order->fresh();

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();

        foreach ($legs as $leg) {
            // Delivered = already succeeded; Pending = already submitted,
            // awaiting an async supplier confirmation (Digiflazz) —
            // neither is ever re-submitted. Everything else (NotStarted,
            // Failed) gets exactly one attempt this pass, same "one call
            // per invocation" discipline fulfill() itself uses.
            if (in_array($leg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                continue;
            }

            $this->attemptLeg($order, $leg);
        }

        return $this->resolveComboOutcome($order);
    }

    /**
     * ADR-094 decision 1/4: one row per real leg — `package_components`
     * quantity > 1 (a repeated component) expands into that many leg
     * rows here, not a single row with a multiplier, so each one gets
     * its own `leg_number`/idempotency key/supplier_reference.
     */
    private function seedDeliveryLegs(Order $order): void
    {
        $legNumber = 1;

        foreach ($order->package->components as $component) {
            $quantity = (int) $component->pivot->quantity;

            for ($i = 0; $i < $quantity; $i++) {
                OrderDeliveryLeg::query()->create([
                    'order_id' => $order->id,
                    'component_package_id' => $component->id,
                    'supplier_id' => $component->supplier_id,
                    'leg_number' => $legNumber,
                    'status' => DeliveryStatus::NotStarted->value,
                ]);

                $legNumber++;
            }
        }
    }

    /**
     * ADR-094 decision 7/26 (idempotency key): `{reference_number}-L{n}`
     * extends the existing ORD-8 scheme rather than replacing it — one
     * real supplier call, one leg row updated, its own short
     * lock+transaction. ADR-026's duplicate_reference ambiguity applies
     * per leg exactly as it does per order: routes to NeedsReview, never
     * a plain Failed, since it's evidence a prior attempt for *this leg's*
     * reference already reached the supplier.
     */
    private function attemptLeg(Order $order, OrderDeliveryLeg $leg): void
    {
        $drawdownPrice = null;
        $legId = $leg->id;

        DB::transaction(function () use ($order, $leg, &$drawdownPrice) {
            $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

            if (in_array($lockedLeg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                // Lost a race with another attempt at this same leg —
                // nothing to do, the other attempt already owns it.
                return;
            }

            $component = $lockedLeg->componentPackage;
            $adapter = $this->supplierAdapters->make($component->supplier->slug);
            $legReferenceNumber = "{$order->reference_number}-L{$lockedLeg->leg_number}";

            Log::withContext(['reference_number' => $legReferenceNumber]);

            $result = $adapter->createOrder(new SupplierOrderRequest(
                productRef: $component->supplier_package_ref,
                referenceNumber: $legReferenceNumber,
                playerId: $order->player_id,
                serverId: $order->server_id,
                customerPhone: $order->customer_phone,
                orderId: $order->id,
            ));

            if ($result->outcome === SupplierOutcome::Pending) {
                $lockedLeg->update(['status' => DeliveryStatus::Pending->value]);

                Log::info('Combo leg pending — awaiting async supplier confirmation', ['leg_id' => $lockedLeg->id]);

                return;
            }

            if ($result->outcome === SupplierOutcome::Failure) {
                $isDuplicateReference = $result->errorCode === 'duplicate_reference';

                $lockedLeg->update([
                    'status' => $isDuplicateReference ? DeliveryStatus::NeedsReview->value : DeliveryStatus::Failed->value,
                    'failure_reason' => $result->errorMessage,
                ]);

                Log::warning($isDuplicateReference ? 'Combo leg ambiguous — needs manual review' : 'Combo leg failed', [
                    'leg_id' => $lockedLeg->id,
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ]);

                return;
            }

            $lockedLeg->update([
                'status' => DeliveryStatus::Delivered->value,
                'supplier_reference' => $result->data['supplier_ref'] ?? null,
                'delivered_at' => now(),
            ]);

            if (isset($result->data['price'])) {
                $drawdownPrice = (float) $result->data['price'];
            }
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($order, $drawdownPrice, $leg->fresh());
        }
    }

    /**
     * ADR-094 decision 9: rolls every leg's current status up into the
     * order's own delivery_status. Precedence, most conservative first:
     *
     *  - any leg Pending → order Pending (still genuinely in flight).
     *  - any leg NeedsReview → order NeedsReview (real ambiguity always
     *    wins — never guess a duplicate_reference leg either way).
     *  - every leg Delivered → order Delivered (credit profit once,
     *    commit any reserved voucher once — order-level, not per-leg,
     *    since platform_profit/affiliate_profit were already frozen
     *    onto the Order as one figure at checkout, ORD-9).
     *  - every leg Failed → order Failed (clean, ordinary failure —
     *    nothing was delivered, "Resend Delivery" retries normally).
     *  - otherwise (a genuine mix of Delivered + Failed, no Pending/
     *    NeedsReview) → order NeedsReview — decision 9's actual partial-
     *    delivery case: the player already has some of the goods, a
     *    human must decide (Issue Voucher for the failed leg's value,
     *    never an automated partial compensation).
     */
    private function resolveComboOutcome(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            // A query-builder pluck() reads the raw column, bypassing the
            // model's enum cast — get()->pluck() hydrates real models
            // first so `status` comes back as DeliveryStatus, not a bare
            // string the comparisons below would never match.
            $statuses = OrderDeliveryLeg::query()->where('order_id', $locked->id)->get()->pluck('status');

            if ($statuses->contains(DeliveryStatus::Pending)) {
                $locked->update(['delivery_status' => $this->orderStatus->markPending($locked->delivery_status)->value]);

                return $locked->fresh();
            }

            if ($statuses->contains(DeliveryStatus::NeedsReview)) {
                $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

                return $locked->fresh();
            }

            if ($statuses->every(fn (DeliveryStatus $status) => $status === DeliveryStatus::Delivered)) {
                $locked->update([
                    'delivery_status' => $this->orderStatus->markDelivered($locked->delivery_status)->value,
                    'delivered_at' => now(),
                ]);

                $this->creditProfit($locked);
                $this->vouchers->commit($locked->id);

                return $locked->fresh();
            }

            if ($statuses->every(fn (DeliveryStatus $status) => $status === DeliveryStatus::Failed)) {
                $locked->update(['delivery_status' => $this->orderStatus->markDeliveryFailed($locked->delivery_status)->value]);

                return $locked->fresh();
            }

            // Mixed Delivered + Failed, no Pending/NeedsReview present —
            // decision 9's partial-delivery case.
            $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

            return $locked->fresh();
        });
    }

    /**
     * ADR-032 decision 3 — the one money path out of Pending, reached
     * by a supplier webhook (primary) or the reconcile poll's own
     * check (backup, CheckSupplierDeliveryJob), never called directly
     * from fulfill() itself. $outcome must be Success or Failure — a
     * Pending order is by definition not yet finalized, so passing
     * Pending here is a caller bug, not a legitimate state.
     *
     * Idempotent by construction: OrderStatusService::finalizePendingSuccess()/
     * finalizePendingFailure() both require the order to currently be
     * Pending, so a second call (e.g. a duplicate webhook delivery)
     * observes the already-advanced state and throws
     * InvalidOrderTransitionException — same lock-then-guard pattern
     * fulfill() itself already uses for the identical PAY-2 reason.
     */
    public function finalizePendingDelivery(Order $order, SupplierOutcome $outcome, ?string $supplierRef = null, mixed $supplierResponse = null): Order
    {
        if ($outcome === SupplierOutcome::Pending) {
            throw new OrderFulfillmentException(
                "finalizePendingDelivery() cannot be called with outcome=pending for order #{$order->id} — a Pending order is not yet finalized",
            );
        }

        $drawdownPrice = null;

        $finalized = DB::transaction(function () use ($order, $outcome, $supplierRef, $supplierResponse, &$drawdownPrice) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            Log::withContext(['reference_number' => $locked->reference_number]);

            if ($outcome === SupplierOutcome::Success) {
                $deliveredStatus = $this->orderStatus->finalizePendingSuccess($locked->delivery_status);

                $locked->update([
                    'supplier_ref' => $supplierRef ?? $locked->supplier_ref,
                    'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                    'delivery_status' => $deliveredStatus->value,
                    'delivered_at' => now(),
                ]);

                $this->creditProfit($locked);
                $this->vouchers->commit($locked->id);

                Log::info('Pending delivery finalized as delivered', ['supplier_ref' => $supplierRef]);

                // ADR-083 decision 3 — the Digiflazz webhook (or the
                // reconcile poll's own checkStatus() re-submit) carries
                // the real `price` here; a `Pending` order's own initial
                // response never did, so this is the only place a
                // Digiflazz drawdown gets recorded.
                if (is_array($supplierResponse) && isset($supplierResponse['price'])) {
                    $drawdownPrice = (float) $supplierResponse['price'];
                }

                return $locked->fresh();
            }

            $failedStatus = $this->orderStatus->finalizePendingFailure($locked->delivery_status);

            $locked->update([
                'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                'delivery_status' => $failedStatus->value,
            ]);

            // Deliberately no voucher/retail-ledger action here — a
            // Pending order finalized as Failed lands on the exact same
            // Failed state a synchronous rejection would, so the
            // existing Failed-only voucher-issuance gate
            // (VoucherController::storeFromOrder()) applies unchanged.
            // No cash was ever taken from OUR ledger for this order, so
            // there is nothing to reverse (ADR-004).
            //
            // ADR-083 decision 3 (grilled 2026-09-11): no supplier-ledger
            // action here either — a `Pending` response never carried a
            // `price` (see the Success branch above), so nothing was
            // ever recorded as drawn down for this order; a `Gagal`
            // after `Pending` writes no `REFUND`. If a real Digiflazz
            // account is later found to actually deduct saldo at
            // `Pending` submission and restore it on `Gagal`, this needs
            // a deliberate `REFUND`/`MANUAL_ADJUSTMENT` branch added
            // here — don't assume it.
            Log::warning('Pending delivery finalized as failed', ['supplier_response' => $supplierResponse]);

            return $locked->fresh();
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($finalized, $drawdownPrice);
        }

        return $finalized;
    }

    /**
     * ADR-026 decision 4a — the one exit from `needs_review` that isn't
     * a retry: an admin manually cross-referenced Gamevion's own
     * dashboard (no automated lookup exists, see this class's own
     * doc comment) and confirmed the real invoice. Deliberately requires
     * that invoice number as input, not just a confirm click — it
     * closes the exact `supplier_ref` gap that caused the ambiguity in
     * the first place, giving this order the same audit trail a normal
     * delivery would have had. Same lock/transaction discipline as
     * fulfill() itself, and the same ledger-credit + voucher-commit
     * calls a normal successful delivery makes — this order genuinely
     * is delivered now, just confirmed by a human instead of a live API
     * response.
     */
    public function markDeliveredManually(Order $order, string $supplierRef, ?string $note, string $confirmedBy): Order
    {
        return DB::transaction(function () use ($order, $supplierRef, $note, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $deliveredStatus = $this->orderStatus->markDeliveredManually($locked->delivery_status);

            Log::withContext(['reference_number' => $locked->reference_number]);

            $locked->update([
                'supplier_ref' => $supplierRef,
                'supplier_response' => [
                    'manually_confirmed' => true,
                    'confirmed_by' => $confirmedBy,
                    'note' => $note,
                    'confirmed_at' => now()->toISOString(),
                ],
                'delivery_status' => $deliveredStatus->value,
                'delivered_at' => now(),
            ]);

            $this->creditProfit($locked);
            $this->vouchers->commit($locked->id);

            Log::info('Delivery manually confirmed after needs_review', [
                'supplier_ref' => $supplierRef,
                'confirmed_by' => $confirmedBy,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Every delivered order writes exactly two order_profit credit
     * entries (PRD §8 LedgerEntry) — kept as two rows even though MVP
     * has only the single internal owner-affiliate, so this never needs
     * to change when Phase 2 onboards real third-party affiliates.
     *
     * ADR-018 decision #6: the single, explicit guard that keeps a
     * sandbox order from ever reaching the real ledger — chosen over a
     * parallel "sandbox fulfillment service" so every other line above
     * this method stays 100% shared and unduplicated between real and
     * test orders.
     */
    private function creditProfit(Order $order): void
    {
        if ($order->is_test) {
            return;
        }

        $this->ledger->credit(LedgerOwnerType::Platform, null, $order->platform_profit, 'order_profit', 'order', $order->id);
        $this->ledger->credit(LedgerOwnerType::Affiliate, $order->affiliate_id, $order->affiliate_profit, 'order_profit', 'order', $order->id);
    }
}
