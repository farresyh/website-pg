<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\SupplierDeliveryCheckService;
use App\Services\Supplier\SupplierAdapterFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-032 decision 5: the reconcile poll's per-order backup to the
 * webhook — ReconcilePendingDeliveriesCommand dispatches this for a
 * stale Pending order (never calls the supplier synchronously from a
 * scheduled command, ADR-014).
 *
 * ADR-096 decision 7: this job is now a thin wrapper around
 * SupplierDeliveryCheckService — the actual checkStatus() + finalize
 * logic (plain order and combo leg-loop alike) lives there, shared
 * with the new admin "Check from Supplier" manual-poll action, so the
 * scheduled and manual paths can never silently drift apart.
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
        (new SupplierDeliveryCheckService($supplierAdapters, $fulfillment))->check($this->order);
    }
}
