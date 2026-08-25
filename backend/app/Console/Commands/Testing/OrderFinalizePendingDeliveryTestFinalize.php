<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper, same pattern as OrderFulfillmentTestFulfill: invoked
 * as a genuinely separate OS process so two finalizePendingDelivery()
 * calls for the same Order (a duplicate webhook delivery, or a webhook
 * racing the reconcile poll) race for real (ADR-032 decision 3).
 * finalizePendingDelivery() never calls the supplier adapter, so no
 * fake binding is needed here — the thing being proven is the Order
 * row lock, not supplier connectivity.
 */
#[Signature('app:order-finalize-pending-delivery-test-finalize {orderId} {resultFile}')]
#[Description('Test-only: attempt to finalize a single Pending order and write the outcome to a result file.')]
class OrderFinalizePendingDeliveryTestFinalize extends Command
{
    public function handle(): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        $service = new OrderFulfillmentService(
            new OrderStatusService(),
            new ReferenceNumberService(),
            app(SupplierAdapterFactory::class),
            new LedgerService(),
            app(VoucherService::class),
        );

        try {
            $service->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-CONCURRENCY-TEST');
            file_put_contents($resultFile, 'success');
        } catch (InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
