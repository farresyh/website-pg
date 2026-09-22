<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Test-only helper, same pattern as LedgerTestWithdraw/VoucherTestRedeem:
 * invoked as a genuinely separate OS process so two fulfillment attempts
 * for the same Order race for real. Builds OrderFulfillmentService with a
 * hardcoded always-succeeding fake SupplierAdapter (not the real
 * GamevionAdapter bound in AppServiceProvider) so this test never makes a
 * real network call — the thing being proven is the Order row lock, not
 * supplier connectivity.
 */
#[Signature('app:order-fulfillment-test-fulfill {orderId} {resultFile}')]
#[Description('Test-only: attempt to fulfill a single order and write the outcome to a result file.')]
class OrderFulfillmentTestFulfill extends Command
{
    public function handle(): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        $adapter = new class implements SupplierAdapter
        {
            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return SupplierResponse::success(['supplier_ref' => 'GV-CONCURRENCY-TEST']);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };

        // ADR-094: a combo order has no supplier_id/supplier of its own
        // (decision 3) — bind under its components' shared supplier
        // instead (decision 4: same-supplier-only in v1).
        $supplierSlug = $order->package?->is_combo
            ? $order->package->components->first()->supplier->slug
            : $order->supplier->slug;

        app()->bind("supplier-adapter.{$supplierSlug}", fn () => $adapter);

        $service = new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            app(SupplierAdapterFactory::class),
            new LedgerService,
            app(VoucherService::class),
            app(SupplierFundingService::class),
            app(CurrencyRateService::class),
        );

        try {
            $service->fulfill($order);
            file_put_contents($resultFile, 'success');
        } catch (InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
