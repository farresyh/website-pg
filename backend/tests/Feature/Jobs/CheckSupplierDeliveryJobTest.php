<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-032 decision 5: the reconcile poll's own per-order check —
 * CheckSupplierDeliveryJob resolves the order's real supplier adapter
 * and calls checkStatus(), routing the normalized outcome through the
 * same finalizePendingDelivery() a webhook would use.
 */
class CheckSupplierDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    private function pendingOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => 'digiflazz-test'],
                ['name' => 'Digiflazz Test', 'api_config' => [], 'currency' => 'IDR'],
            )->id;

        return Order::query()->create(array_merge([
            'order_number' => 'KRS-CHECK-TEST-1',
            'reference_number' => 'REF-CHECK-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'xld10',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ], $overrides));
    }

    private function fulfillmentService(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.digiflazz-test', fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService(),
            new ReferenceNumberService(),
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService(),
            new VoucherService(new LedgerService()),
        );
    }

    private function checkStatusAdapter(SupplierResponse $response): SupplierAdapter
    {
        return new class($response) implements SupplierAdapter
        {
            public function __construct(private readonly SupplierResponse $response)
            {
            }

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
                throw new RuntimeException('not used in this test');
            }

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                return $this->response;
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    public function test_finalizes_as_delivered_when_supplier_confirms_success(): void
    {
        $order = $this->pendingOrder(['platform_profit' => 150, 'reseller_profit' => 50]);
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::success(['supplier_ref' => 'DGFLZ-CHECK-1']),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $fresh = $order->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $this->assertSame('DGFLZ-CHECK-1', $fresh->supplier_ref);
        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }

    public function test_finalizes_as_failed_when_supplier_confirms_failure(): void
    {
        $order = $this->pendingOrder();
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::failure('Gagal', 'Transaction failed'),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $fresh = $order->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->delivery_status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /** Decision 5: still-Pending stays for the next scheduled run — no state change, no error. */
    public function test_leaves_the_order_pending_when_the_supplier_is_still_pending(): void
    {
        $order = $this->pendingOrder();
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::pending(['status' => 'Pending']),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * A webhook can beat the poll to the same order — the job's own
     * finalize call then observes an already-advanced state and must
     * swallow the transition rejection, not fail the job.
     */
    public function test_swallows_an_already_advanced_order_without_failing_the_job(): void
    {
        Log::spy();
        $order = $this->pendingOrder(['delivery_status' => DeliveryStatus::Delivered->value]);
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::success(['supplier_ref' => 'DGFLZ-CHECK-2']),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    /** ADR-020 decision #5 — same queue as FulfillOrderJob, same reasoning. */
    public function test_runs_on_the_orders_queue(): void
    {
        $job = new CheckSupplierDeliveryJob($this->pendingOrder());

        $this->assertSame('orders', $job->queue);
    }
}
