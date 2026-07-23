<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates checkout COMPLETION — submitting a paid order to the
 * supplier and recording the outcome. Assumes payment_status is
 * already genuinely Paid (set by whatever verifies and processes the
 * payment-gateway webhook — that verification/idempotency check
 * happens before this is ever called, not inside it).
 *
 * Deliberately does NOT attempt automatic reconciliation on a
 * duplicate-reference response — Gamevion's error-body shape for that
 * case isn't confirmed (see GamevionAdapter), and the real
 * reconciliation job (ORD-10) is separately scoped and not built yet.
 * Any supplier failure, including duplicate-reference, is surfaced as
 * delivery_status=failed with the raw adapter response attached, for
 * admin review (ORD-7) — never auto-retried or silently swallowed.
 */
final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ReferenceNumberService $referenceNumbers,
        private readonly SupplierAdapter $supplier,
        private readonly LedgerService $ledger,
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

            // ORD-8: generated once, reused on every retry of this
            // order — resolve() returns the existing value unchanged
            // if this is a retry after a prior failure.
            $referenceNumber = $this->referenceNumbers->resolve($locked->reference_number);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            $result = $this->supplier->createOrder(new SupplierOrderRequest(
                productRef: $locked->supplier_product_ref,
                referenceNumber: $referenceNumber,
                playerId: $locked->player_id,
                serverId: $locked->server_id,
                customerPhone: $locked->customer_phone,
            ));

            if (! $result->success) {
                $locked->update([
                    'delivery_status' => $this->orderStatus->markDeliveryFailed($processingStatus)->value,
                    'supplier_response' => [
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ],
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'supplier_ref' => $result->data['supplier_ref'] ?? null,
                'supplier_response' => $result->data,
                'delivery_status' => $this->orderStatus->markDelivered($processingStatus)->value,
            ]);

            $this->creditProfit($locked);

            return $locked->fresh();
        });
    }

    /**
     * Every delivered order writes exactly two order_profit credit
     * entries (PRD §8 LedgerEntry) — kept as two rows even though MVP
     * has only the single internal owner-reseller, so this never needs
     * to change when Phase 2 onboards real third-party resellers.
     */
    private function creditProfit(Order $order): void
    {
        $this->ledger->credit('platform', null, $order->platform_profit, 'order_profit', 'order', $order->id);
        $this->ledger->credit('reseller', $order->reseller_id, $order->reseller_profit, 'order_profit', 'order', $order->id);
    }
}
