<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierStatusCheckRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ADR-032 decision 5: the reconcile poll's per-order backup to the
 * webhook — ReconcilePendingDeliveriesCommand dispatches this for a
 * stale Pending order (never calls the supplier synchronously from a
 * scheduled command, ADR-014). Resolves the order's own supplier
 * adapter, calls checkStatus(), and routes the normalized outcome
 * through the same finalizePendingDelivery() a webhook would use — a
 * still-Pending result is left as-is for the next scheduled run.
 */
final class CheckSupplierDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public readonly Order $order,
    ) {
        // ADR-020 decision #5 — same queue as FulfillOrderJob: this is
        // money-adjacent and customer-facing, kept off the slower
        // price-sync queue.
        $this->onQueue('orders');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SupplierAdapterFactory $supplierAdapters, OrderFulfillmentService $fulfillment): void
    {
        Log::withContext(['order_number' => $this->order->order_number]);

        $adapter = $supplierAdapters->make($this->order->supplier->slug);

        // ADR-030 decision 1 / ADR-032 addendum: checkStatus() is a
        // status re-submit keyed on our own reference_number
        // (Digiflazz's `ref_id`) — not supplier_ref, which a genuinely
        // Pending order may not have received yet. Digiflazz's own
        // re-submit also requires the original buyer_sku_code +
        // customer_no (confirmed against their real docs while
        // building DigiflazzAdapter), which is why this request
        // carries productRef/playerId/serverId too, not just the ref.
        $result = $adapter->checkStatus(new SupplierStatusCheckRequest(
            supplierRef: $this->order->reference_number,
            productRef: $this->order->supplier_product_ref,
            playerId: $this->order->player_id,
            serverId: $this->order->server_id,
            orderId: $this->order->id,
        ));

        try {
            match ($result->outcome) {
                SupplierOutcome::Success => $fulfillment->finalizePendingDelivery(
                    $this->order,
                    SupplierOutcome::Success,
                    $result->data['supplier_ref'] ?? null,
                    $result->data,
                ),
                SupplierOutcome::Failure => $fulfillment->finalizePendingDelivery(
                    $this->order,
                    SupplierOutcome::Failure,
                    null,
                    ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage],
                ),
                // Decision 5: still-Pending stays for the next run — not an error.
                SupplierOutcome::Pending => null,
            };
        } catch (InvalidOrderTransitionException $e) {
            // A webhook delivery already finalized this order before
            // this poll's own lock acquisition — expected outcome, not
            // a job failure. Same reasoning as FulfillOrderJob's own
            // already-advanced guard.
            Log::info('CheckSupplierDeliveryJob skipped: order already finalized', ['reason' => $e->getMessage()]);
        }
    }
}
