<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
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
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-094 decisions 7-11, 18-20 (Phase 3, core — synchronous legs
 * only; Digiflazz's async Pending-then-webhook per-leg resolution is a
 * separate follow-up phase, not built here).
 */
class OrderFulfillmentServiceComboTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_SUPPLIER_SLUG = 'test-supplier';

    private function service(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.'.self::DEFAULT_SUPPLIER_SLUG, fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService,
            new VoucherService(new LedgerService),
            new SupplierFundingService,
        );
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['slug' => self::DEFAULT_SUPPLIER_SLUG],
            ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
        );
    }

    private function componentPackage(Supplier $supplier, int $gameId, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $gameId, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'REF-'.uniqid(),
        ], $overrides));
    }

    private function comboPackage(int $gameId, array $components): Package
    {
        $combo = Package::query()->create([
            'game_id' => $gameId, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);

        foreach ($components as $index => ['package' => $component, 'quantity' => $quantity]) {
            $combo->components()->attach($component->id, ['quantity' => $quantity, 'sort_order' => $index]);
        }

        return $combo;
    }

    private function paidComboOrder(Package $combo, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-COMBO-1',
            'customer_email' => 'buyer@example.com',
            'game_id' => $combo->game_id,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => $combo->cost_price,
            'standard_selling_price' => $combo->standard_selling_price,
            'selling_price' => 1500,
            'transaction_fee' => 100,
            'final_amount' => 1600,
            'platform_profit' => 200,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /**
     * Consumes one queued SupplierResponse per createOrder() call, in
     * call order — deterministic since attemptLeg() walks legs in
     * ascending leg_number.
     */
    private function queuedAdapter(array $responses): SupplierAdapter
    {
        return new class($responses) implements SupplierAdapter
        {
            private int $index = 0;

            public function __construct(private readonly array $responses) {}

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
                return $this->responses[$this->index++] ?? throw new RuntimeException('no more queued responses');
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

    public function test_full_success_delivers_the_order_and_records_a_leg_per_component(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 480]),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertNotNull($result->delivered_at);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertCount(2, $legs);
        $this->assertSame(1, $legs[0]->leg_number);
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame('SREF-A', $legs[0]->supplier_reference);
        $this->assertSame(2, $legs[1]->leg_number);
        $this->assertSame(DeliveryStatus::Delivered, $legs[1]->status);
        $this->assertSame('SREF-B', $legs[1]->supplier_reference);

        // Decision 11/18: one SupplierLedgerEntry per leg, not one per order.
        $this->assertSame(2, SupplierLedgerEntry::query()->count());
        $this->assertSame('order_delivery_leg', SupplierLedgerEntry::query()->first()->reference_type);

        // Profit is credited once for the whole order (ORD-9's frozen figure).
        $this->assertSame(1, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->count());
    }

    public function test_the_idempotency_key_extends_the_order_reference_number_per_leg(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $capturedReferenceNumber = null;
        $adapter = new class($capturedReferenceNumber) implements SupplierAdapter
        {
            public function __construct(private mixed &$captured) {}

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('n/a');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('n/a');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                $this->captured = $request->referenceNumber;

                return SupplierResponse::success(['supplier_ref' => 'SREF']);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('n/a');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('n/a');
            }
        };

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame($result->reference_number.'-L1', $capturedReferenceNumber);
    }

    public function test_one_leg_failing_lands_the_order_in_needs_review_when_the_other_succeeded(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::failure('insufficient_balance', 'No balance'),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $this->assertNull($result->delivered_at);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame(DeliveryStatus::Failed, $legs[1]->status);

        // No profit credited yet — a needs_review order is not a clean delivery.
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());
        // The succeeded leg's own drawdown is still recorded — it's real.
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
    }

    public function test_every_leg_failing_lands_the_order_in_plain_failed(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::failure('invalid_product', 'Bad product'),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    public function test_a_duplicate_reference_leg_lands_the_whole_order_in_needs_review(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A']),
            SupplierResponse::failure('duplicate_reference', 'Already submitted'),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::NeedsReview, $legs[1]->status);
    }

    /**
     * Decision 4's own example: the same component repeated (quantity 2)
     * expands into 2 real legs, each its own supplier call/idempotency
     * key/leg_number — not one call for "2 units".
     */
    public function test_a_repeated_component_expands_into_two_real_legs(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 2]]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-1']),
            SupplierResponse::success(['supplier_ref' => 'SREF-2']),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertCount(2, $legs);
        $this->assertSame([1, 2], $legs->pluck('leg_number')->all());
        $this->assertSame([$a->id, $a->id], $legs->pluck('component_package_id')->all());
    }

    /**
     * Decision 7/10: "Resend Delivery" is just calling fulfill() again —
     * a needs_review combo whose failed leg now succeeds transitions
     * cleanly to Delivered, crediting profit exactly once (not once per
     * attempt).
     */
    public function test_resend_delivery_retries_only_the_not_yet_succeeded_leg(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $firstAttemptAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A']),
            SupplierResponse::failure('insufficient_balance', 'No balance'),
        ]);
        $firstResult = $this->service($firstAttemptAdapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::NeedsReview, $firstResult->delivery_status);

        // Retry: only leg B (still Failed) should get a new supplier call.
        $retryAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-B-RETRY']),
        ]);
        $secondResult = $this->service($retryAdapter)->fulfill($firstResult->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $secondResult->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame('SREF-A', $legs[0]->supplier_reference); // untouched by the retry
        $this->assertSame('SREF-B-RETRY', $legs[1]->supplier_reference);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->count());
    }
}
