<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Accounting\SupplierFundingService;
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
use App\Services\Supplier\SupplierStatusCheckRequest;
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
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-CHECK-TEST-1',
            'reference_number' => 'REF-CHECK-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'xld10',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ], $overrides));
    }

    private function fulfillmentService(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.digiflazz-test', fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService,
            new VoucherService(new LedgerService),
            new SupplierFundingService,
        );
    }

    private function checkStatusAdapter(SupplierResponse $response): SupplierAdapter
    {
        return new class($response) implements SupplierAdapter
        {
            public function __construct(private readonly SupplierResponse $response) {}

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

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
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
        $order = $this->pendingOrder(['platform_profit' => 150, 'affiliate_profit' => 50]);
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

    /**
     * ADR-094 decision 7 (Phase 3b): a combo order's still-Pending
     * legs each get their own checkStatus() call, resolved through
     * finalizePendingDeliveryLeg() — a Digiflazz-shaped supplier
     * confirming Sukses on the last Pending leg completes the order.
     */
    private function comboOrderWithLegs(array $legStatuses): array
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'digiflazz-test'],
            ['name' => 'Digiflazz Test', 'api_config' => [], 'currency' => 'IDR'],
        );
        $game = Game::query()->create(['name' => 'MLBB Poll Test', 'slug' => 'mlbb-poll-test-'.uniqid()]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-CHECK-COMBO-1',
            'reference_number' => 'REF-CHECK-COMBO-1',
            'customer_email' => 'buyer@example.com',
            'game_id' => $game->id,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => 500,
            'standard_selling_price' => 600,
            'selling_price' => 700,
            'transaction_fee' => 100,
            'final_amount' => 800,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $legs = [];
        foreach ($legStatuses as $i => $status) {
            $component = Package::query()->create([
                'game_id' => $game->id, 'name' => "Component {$i}", 'denomination' => 50,
                'cost_price' => 250, 'standard_selling_price' => 300, 'markup_percent' => 20,
                'supplier_id' => $supplier->id, 'supplier_package_ref' => "sku-{$i}",
            ]);
            $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => $i]);

            $legs[] = OrderDeliveryLeg::query()->create([
                'order_id' => $order->id,
                'component_package_id' => $component->id,
                'supplier_id' => $supplier->id,
                'leg_number' => $i + 1,
                'status' => $status,
            ]);
        }

        return [$order, $legs];
    }

    public function test_a_combo_orders_pending_leg_completes_the_order_when_the_supplier_confirms_success(): void
    {
        [$order, $legs] = $this->comboOrderWithLegs([DeliveryStatus::Delivered->value, DeliveryStatus::Pending->value]);
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::success(['supplier_ref' => 'DGFLZ-COMBO-LEG-2', 'price' => 250]),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $this->assertSame(DeliveryStatus::Delivered, $legs[1]->fresh()->status);
        $this->assertSame('DGFLZ-COMBO-LEG-2', $legs[1]->fresh()->supplier_reference);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_combo_orders_pending_leg_failing_lands_the_order_in_needs_review(): void
    {
        [$order, $legs] = $this->comboOrderWithLegs([DeliveryStatus::Delivered->value, DeliveryStatus::Pending->value]);
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::failure('Gagal', 'Transaction failed'),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $this->assertSame(DeliveryStatus::Failed, $legs[1]->fresh()->status);
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_a_combo_order_with_two_pending_legs_only_checks_the_ones_still_pending(): void
    {
        [$order, $legs] = $this->comboOrderWithLegs([DeliveryStatus::Pending->value, DeliveryStatus::Failed->value]);
        // Only the Pending leg (leg 1) should get a checkStatus() call —
        // the already-terminal Failed leg (leg 2) must never be re-checked.
        $fulfillment = $this->fulfillmentService($this->checkStatusAdapter(
            SupplierResponse::success(['supplier_ref' => 'DGFLZ-COMBO-LEG-1']),
        ));

        (new CheckSupplierDeliveryJob($order))->handle($this->app->make(SupplierAdapterFactory::class), $fulfillment);

        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $legs[1]->fresh()->status); // untouched
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }
}
