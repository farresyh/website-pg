<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Models\Package;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Test-only helper, same pattern as OrderFulfillmentTestFulfill: a
 * genuinely separate OS process, so two resends of one order race for
 * real (ADR-105 2026-10-06 decision 14). The fake supplier is slow so the
 * other process's attempt lands while this one is in flight.
 */
#[Signature('app:order-resend-test-resend {orderId} {packageId} {resultFile}')]
#[Description('Test-only: resend one order to one package and write the outcome to a result file.')]
class OrderResendTestResend extends Command
{
    public function handle(): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $package = Package::query()->findOrFail((int) $this->argument('packageId'));
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
                usleep(500_000);

                return SupplierResponse::success(['supplier_ref' => 'GV-RESEND-RACE']);
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

        app()->bind("supplier-adapter.{$package->supplier->slug}", fn () => $adapter);

        $service = new OrderResendService(new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            app(SupplierAdapterFactory::class),
            new LedgerService,
            app(VoucherService::class),
            app(SupplierFundingService::class),
            app(CurrencyRateService::class),
        ));

        try {
            $service->resend($order, $package, null, 'race');
            file_put_contents($resultFile, 'success');
        } catch (ValidationException|InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
