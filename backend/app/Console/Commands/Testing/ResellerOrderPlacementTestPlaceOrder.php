<?php

namespace App\Console\Commands\Testing;

use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/ResellerOrderPlacementConcurrencyTest.php so two
 * wallet-debited order placements race for real against the same
 * ledger_accounts row (separate PHP process + separate DB connection
 * each) — same shape as LedgerTestWithdraw.
 *
 * `placeOrder()` dispatches `FulfillOrderJob` after commit, and
 * `QUEUE_CONNECTION=sync` (phpunit.concurrency.xml) runs that inline —
 * so this command binds the same hardcoded always-succeeding fake
 * `SupplierAdapter` `OrderFulfillmentTestFulfill` uses, under the real
 * Supplier row's slug the concurrency test creates, purely so
 * fulfillment never makes a real network call. The thing being proven
 * here is the wallet ledger lock, not supplier connectivity.
 */
#[Signature('app:reseller-order-test-place {resellerId} {supplierId} {costPriceSen} {standardSellingPriceSen} {idempotencyKey} {resultFile}')]
#[Description('Test-only: attempt a single wallet order placement and write the outcome to a result file.')]
class ResellerOrderPlacementTestPlaceOrder extends Command
{
    public function handle(ResellerOrderPlacementService $service): int
    {
        $reseller = Reseller::query()->findOrFail((int) $this->argument('resellerId'));
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
                return SupplierResponse::success(['supplier_ref' => 'RESELLER-CONCURRENCY-TEST']);
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

        $supplierId = (int) $this->argument('supplierId');
        $supplierSlug = Supplier::query()->findOrFail($supplierId)->slug;
        app()->bind("supplier-adapter.{$supplierSlug}", fn () => $adapter);

        try {
            $order = $service->placeOrder($reseller, new ResellerOrderPlacementRequest(
                playerId: 'concurrency-test-player',
                serverId: null,
                costPriceSen: (int) $this->argument('costPriceSen'),
                standardSellingPriceSen: (int) $this->argument('standardSellingPriceSen'),
                idempotencyKey: $this->argument('idempotencyKey'),
                supplierProductRef: 'CONCURRENCY-TEST-REF',
                supplierId: $supplierId,
            ));
            file_put_contents($resultFile, 'success:'.$order->order_number);
        } catch (InsufficientBalanceException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
