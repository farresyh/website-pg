<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Ledger\LedgerService;
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
    ) {
    }

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
        return DB::transaction(function () use ($order) {
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

        return DB::transaction(function () use ($order, $outcome, $supplierRef, $supplierResponse) {
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

                return $locked->fresh();
            }

            $failedStatus = $this->orderStatus->finalizePendingFailure($locked->delivery_status);

            $locked->update([
                'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                'delivery_status' => $failedStatus->value,
            ]);

            // Deliberately no voucher/ledger action here — a Pending
            // order finalized as Failed lands on the exact same Failed
            // state a synchronous rejection would, so the existing
            // Failed-only voucher-issuance gate
            // (VoucherController::storeFromOrder()) applies unchanged.
            // No cash was ever taken from the ledger for this order, so
            // there is nothing to reverse (ADR-004).
            Log::warning('Pending delivery finalized as failed', ['supplier_response' => $supplierResponse]);

            return $locked->fresh();
        });
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
     * has only the single internal owner-reseller, so this never needs
     * to change when Phase 2 onboards real third-party resellers.
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

        $this->ledger->credit('platform', null, $order->platform_profit, 'order_profit', 'order', $order->id);
        $this->ledger->credit('reseller', $order->reseller_id, $order->reseller_profit, 'order_profit', 'order', $order->id);
    }
}
