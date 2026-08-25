<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FulfillOrderJob;
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
 * ADR-014: FulfillOrderJob is the thing the Xendit webhook (and ORD-7's
 * manual retry action) dispatch instead of calling
 * OrderFulfillmentService::fulfill() inline.
 */
class FulfillOrderJobTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => 'gamevion'],
                ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
            )->id;

        return Order::query()->create(array_merge([
            'order_number' => 'KRS-JOB-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    private function fulfillmentService(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.gamevion', fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService(),
            new ReferenceNumberService(),
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService(),
            new VoucherService(new LedgerService()),
        );
    }

    private function fakeSupplierAdapter(bool $success, array $data = []): SupplierAdapter
    {
        return new class($success, $data) implements SupplierAdapter
        {
            public function __construct(private readonly bool $success, private readonly array $data) {}

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
                return $this->success
                    ? SupplierResponse::success($this->data)
                    : SupplierResponse::failure('500', 'Server error');
            }

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    public function test_tries_and_backoff_match_the_adr_014_policy(): void
    {
        $order = $this->paidOrder();
        $job = new FulfillOrderJob($order);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    /** ADR-020 decision #5 — dedicated queue so a slow Price Sync run can never head-of-line-block this. */
    public function test_runs_on_the_orders_queue(): void
    {
        $job = new FulfillOrderJob($this->paidOrder());

        $this->assertSame('orders', $job->queue);
    }

    public function test_handle_fulfills_the_order(): void
    {
        $order = $this->paidOrder();
        $job = new FulfillOrderJob($order);

        $job->handle($this->fulfillmentService($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-JOB-1'])));

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $this->assertSame('GV-JOB-1', $order->fresh()->supplier_ref);
    }

    /**
     * A concurrent webhook delivery (or, once ORD-7 exists, an admin
     * retry racing a queued webhook job) can advance the order past
     * NotStarted/Failed before this job's own lock acquisition —
     * fulfill()'s guard throws InvalidOrderTransitionException, which
     * handle() must swallow (log only), not let fail the job.
     */
    public function test_handle_swallows_an_already_advanced_order_without_failing_the_job(): void
    {
        Log::spy();

        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Delivered->value]);
        $job = new FulfillOrderJob($order);

        $job->handle($this->fulfillmentService($this->fakeSupplierAdapter(true)));

        Log::shouldHaveReceived('info')->once();
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }
}
