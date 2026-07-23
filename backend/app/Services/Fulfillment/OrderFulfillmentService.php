<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;

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

    public function fulfill(Order $order): Order
    {
        // ORD-11's central guard: delivery may only start once payment
        // is genuinely paid. OrderStatusService throws otherwise — the
        // single most direct path to giving away free game credits
        // without confirmed payment, never bypassed here.
        $processingStatus = $this->orderStatus->startDelivery($order->payment_status, $order->delivery_status);

        // ORD-8: generated once, reused on every retry of this order —
        // resolve() returns the existing value unchanged if this is a
        // retry after a prior failure.
        $referenceNumber = $this->referenceNumbers->resolve($order->reference_number);

        $order->update([
            'reference_number' => $referenceNumber,
            'delivery_status' => $processingStatus->value,
        ]);

        $result = $this->supplier->createOrder(new SupplierOrderRequest(
            productRef: (string) $order->supplier_product_ref,
            referenceNumber: $referenceNumber,
            playerId: $order->player_id,
            serverId: $order->server_id,
            customerPhone: $order->customer_phone,
        ));

        if (! $result->success) {
            $order->update([
                'delivery_status' => $this->orderStatus->markDeliveryFailed($processingStatus)->value,
                'supplier_response' => [
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ],
            ]);

            return $order->fresh();
        }

        $order->update([
            'supplier_ref' => $result->data['supplier_ref'] ?? null,
            'supplier_response' => $result->data,
            'delivery_status' => $this->orderStatus->markDelivered($processingStatus)->value,
        ]);

        $this->creditProfit($order);

        return $order->fresh();
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
