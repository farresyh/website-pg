<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ResendOrderDeliveryJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-017 / ADR-014: ResendOrderDeliveryJob is what OrderController::resend()
 * dispatches instead of calling OrderResendService::resend() inline.
 */
class ResendOrderDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    private function resendService(SupplierAdapter $adapter): OrderResendService
    {
        $this->app->bind('supplier-adapter.gamevion', fn () => $adapter);

        return new OrderResendService(
            new OrderFulfillmentService(
                new OrderStatusService(),
                new ReferenceNumberService(),
                $this->app->make(SupplierAdapterFactory::class),
                new LedgerService(),
                new VoucherService(new LedgerService()),
            ),
            new PricingService(),
            new MembershipPricingService(),
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

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    private function failedOrderWithPackage(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-RESEND-JOB-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => $package->supplier_package_ref,
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'reseller_markup_pct' => 0,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        return [$order, $package];
    }

    public function test_tries_and_backoff_match_the_adr_014_policy(): void
    {
        [$order, $package] = $this->failedOrderWithPackage();
        $job = new ResendOrderDeliveryJob($order, $package->id, null, 'Admin');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    /** ADR-020 decision #5 — same queue as FulfillOrderJob, same reasoning. */
    public function test_runs_on_the_orders_queue(): void
    {
        [$order, $package] = $this->failedOrderWithPackage();
        $job = new ResendOrderDeliveryJob($order, $package->id, null, 'Admin');

        $this->assertSame('orders', $job->queue);
    }

    public function test_handle_resends_the_order_against_the_given_package(): void
    {
        [$order, $package] = $this->failedOrderWithPackage();
        $job = new ResendOrderDeliveryJob($order, $package->id, 'Bigger pack', 'Admin User');

        $job->handle($this->resendService($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-JOB-1'])));

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame('Admin User', $attempt->triggered_by);
    }

    public function test_handle_logs_and_stops_when_the_target_package_no_longer_exists(): void
    {
        Log::spy();
        [$order, $package] = $this->failedOrderWithPackage();
        $deletedPackageId = $package->id + 999;
        $job = new ResendOrderDeliveryJob($order, $deletedPackageId, null, 'Admin');

        $job->handle($this->resendService($this->fakeSupplierAdapter(true)));

        Log::shouldHaveReceived('error')->once();
        $this->assertSame(0, OrderResendAttempt::query()->count());
    }

    public function test_handle_swallows_a_validation_rejection_without_failing_the_job(): void
    {
        Log::spy();
        [$order, $package] = $this->failedOrderWithPackage();
        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]); // no longer resendable by the time the job runs
        $job = new ResendOrderDeliveryJob($order, $package->id, null, 'Admin');

        $job->handle($this->resendService($this->fakeSupplierAdapter(true)));

        Log::shouldHaveReceived('info')->once();
        $this->assertSame(0, OrderResendAttempt::query()->count());
    }
}
