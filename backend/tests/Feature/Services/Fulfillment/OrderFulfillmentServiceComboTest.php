<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
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
            new CurrencyRateService,
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

    /**
     * 2026-09-16 addendum — decision 20's cap raised 3->5. The leg loop
     * itself is leg-count-generic (proven by the 2-leg test above); this
     * proves it actually holds at the new cap's max, not just that
     * nothing in the code hardcodes 3.
     */
    public function test_full_success_at_the_five_leg_cap_delivers_every_leg(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-'.uniqid()])->id;
        $components = collect(range(1, 5))->map(fn () => $this->componentPackage($supplier, $gameId));
        $combo = $this->comboPackage($gameId, $components->map(fn (Package $p) => ['package' => $p, 'quantity' => 1])->all());
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter(
            $components->map(fn (Package $p, int $i) => SupplierResponse::success(['supplier_ref' => "SREF-{$i}", 'price' => 480]))->all(),
        );

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertCount(5, $legs);
        $this->assertTrue($legs->every(fn (OrderDeliveryLeg $leg) => $leg->status === DeliveryStatus::Delivered));
        $this->assertSame([1, 2, 3, 4, 5], $legs->pluck('leg_number')->all());
        $this->assertSame(5, SupplierLedgerEntry::query()->count());
    }

    /**
     * ADR-097 decision 15 — a combo leg resolves the separator from
     * `$order->game`, not the leg's own component's game: same-game-
     * only combos (`StoreComboPackageRequest`) mean they're identical,
     * and this proves the leg loop actually forwards it, not just the
     * plain single-ref path.
     */
    public function test_attempt_leg_forwards_the_orders_game_customer_no_separator_override(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create([
            'name' => 'MLBB', 'slug' => 'mlbb-'.uniqid(),
            'validation_rules' => ['customer_no_separator' => 'pipe'],
        ])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $captured = null;
        $adapter = new class($captured) implements SupplierAdapter
        {
            public ?SupplierOrderRequest $captured = null;

            public function __construct(&$captured) {}

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
                $this->captured = $request;

                return SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]);
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

        $this->service($adapter)->fulfill($order);

        $this->assertSame('pipe', $adapter->captured?->customerNoSeparator);
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

        // ADR-103 decision 1 — the value is now PERSISTED on the leg
        // itself, not just derived on the fly for this one call.
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($capturedReferenceNumber, $leg->reference_number);
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

        // ADR-103 decision 2 — a plain Failed leg never writes the
        // unsafe flag (only a NeedsReview outcome does).
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertNull($leg->resend_unsafe_with_same_reference);
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
            SupplierResponse::failure('duplicate_reference', 'Already submitted', resendUnsafeWithSameReference: true),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::NeedsReview, $legs[1]->status);
    }

    /**
     * ADR-102 decision 4/8 — a confirmed-Gagal leg (Digiflazz's own
     * status field said so, unlike Gamevion's ambiguous duplicate_reference
     * above) lands that leg on Failed, not NeedsReview — no new
     * per-leg infra needed, this falls straight out of decision 4's
     * split-flag routing (also used by isPartialComboDelivery()'s
     * already-existing "clean Delivered+Failed mix" bucket).
     */
    public function test_a_confirmed_gagal_leg_lands_that_leg_in_failed_not_needs_review(): void
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
            SupplierResponse::failure('02', 'Transaksi Gagal', resendUnsafeWithSameReference: true, outcomeConfirmedFailed: true),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        // Mixed Delivered + Failed, no Pending/NeedsReview leg present —
        // decision 9's existing partial-delivery bucket, unchanged.
        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Failed, $legs[1]->status);
    }

    /**
     * ADR-094 2026-09-15 resilience addendum: a genuinely unexpected
     * exception (not a clean SupplierResponse::failure()) used to
     * strand the whole order at Processing forever — found live via a
     * real local smoke test (an unconfigured supplier slug threw
     * UnsupportedSupplierException, order never left Processing).
     * attemptLeg() now catches this the same way duplicate_reference
     * is already handled: NeedsReview, never Failed, since we don't
     * know whether the supplier actually received the request.
     */
    public function test_a_leg_that_throws_unexpectedly_lands_in_needs_review_not_stranded_at_processing(): void
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

        $adapter = new class implements SupplierAdapter
        {
            private int $calls = 0;

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
                $this->calls++;
                if ($this->calls === 2) {
                    throw new RuntimeException('Connection timed out');
                }

                return SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]);
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

        $result = $this->service($adapter)->fulfill($order);

        // The order itself never gets stuck at Processing — it always
        // resolves to a real outcome, same as if leg 2 had returned a
        // clean SupplierResponse::failure().
        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame(DeliveryStatus::NeedsReview, $legs[1]->status);
        $this->assertStringContainsString('Connection timed out', $legs[1]->failure_reason);

        // recordOrderDrawdown() must never fire for the leg that threw
        // — only the genuinely delivered leg draws down the supplier
        // funding ledger.
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
    }

    /**
     * The retry path (decision 10's "ordinary Resend Delivery retry,
     * no package swap") re-submits a NeedsReview-from-exception leg
     * under the same idempotency key exactly like any other
     * not-yet-succeeded leg — no special handling needed for it to
     * recover once the underlying issue (e.g. connectivity) clears.
     */
    public function test_retrying_a_leg_that_previously_threw_completes_the_order_normally(): void
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

        $throwingAdapter = new class implements SupplierAdapter
        {
            private int $calls = 0;

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
                $this->calls++;
                if ($this->calls === 2) {
                    throw new RuntimeException('Connection timed out');
                }

                return SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]);
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
        $this->service($throwingAdapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);

        // Connectivity's fine now — the retry only re-attempts the one
        // leg that isn't yet Delivered (decision 7's own idempotency).
        $recoveredAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-B-RETRY', 'price' => 480]),
        ]);

        $result = $this->service($recoveredAdapter)->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame('SREF-A', $legs[0]->supplier_reference);
        $this->assertSame('SREF-B-RETRY', $legs[1]->supplier_reference);
        $this->assertSame(2, SupplierLedgerEntry::query()->count());
    }

    /**
     * ADR-106 addendum (2026-09-21) — closes decision 1's own
     * "non-combo only for now" deferral: a leg's very first attempt now
     * writes a durable `attempt_type=initial` row of its own, one per
     * leg, mirroring the order-level table's own `initial` semantics.
     */
    public function test_full_success_writes_a_durable_initial_attempt_row_per_leg(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 600]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 480]),
        ]);

        $this->service($adapter)->fulfill($order);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $attempts = OrderResendAttempt::query()->where('order_id', $order->id)->orderBy('order_delivery_leg_id')->get();
        $this->assertCount(2, $attempts);
        $this->assertTrue($attempts->every(fn (OrderResendAttempt $a) => $a->attempt_type === 'initial'));
        $this->assertTrue($attempts->every(fn (OrderResendAttempt $a) => $a->outcome === 'success'));
        $this->assertSame($legs->pluck('id')->sort()->values()->all(), $attempts->pluck('order_delivery_leg_id')->sort()->values()->all());
        $this->assertSame($a->id, $attempts->firstWhere('order_delivery_leg_id', $legs[0]->id)->package_id);
        $this->assertSame(500, $attempts->firstWhere('order_delivery_leg_id', $legs[0]->id)->cost_price_sen);
        $this->assertNull($attempts->first()->triggered_by);
    }

    /**
     * ADR-106 addendum (2026-09-21) — a leg retry writes its own
     * `attempt_type=retry` row (not a second `initial`), with
     * `triggered_by`/`note` threaded from fulfill()/fulfillCombo() down
     * to attemptLeg() — mirrors the non-combo retry row exactly, just
     * scoped to the one leg actually retried.
     */
    public function test_retrying_a_failed_leg_writes_a_durable_retry_attempt_row(): void
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

        $firstPass = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::failure('timeout', 'Supplier timed out'),
        ]);
        $this->service($firstPass)->fulfill($order);
        // One leg Delivered + one Failed rolls the order up to NeedsReview
        // (decision 9's own precedence) — the leg itself is still plainly
        // Failed, which is what matters for this test.
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Failed, OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->sole()->status);

        $retryPass = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-B-RETRY', 'price' => 480]),
        ]);
        $this->service($retryPass)->fulfill($order->fresh(), 'Admin User', 'Retried after checking Gamevion dashboard');

        $legB = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->sole();
        $attemptsOnLegB = OrderResendAttempt::query()->where('order_delivery_leg_id', $legB->id)->orderBy('id')->get();
        $this->assertCount(2, $attemptsOnLegB);
        $this->assertSame('initial', $attemptsOnLegB[0]->attempt_type);
        $this->assertSame('failed', $attemptsOnLegB[0]->outcome);
        $this->assertNull($attemptsOnLegB[0]->triggered_by);
        $this->assertSame('retry', $attemptsOnLegB[1]->attempt_type);
        $this->assertSame('success', $attemptsOnLegB[1]->outcome);
        $this->assertSame('Admin User', $attemptsOnLegB[1]->triggered_by);
        $this->assertSame('Retried after checking Gamevion dashboard', $attemptsOnLegB[1]->note);

        // Leg A only ever succeeded once — no retry row for it.
        $legA = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 1)->sole();
        $this->assertSame(1, OrderResendAttempt::query()->where('order_delivery_leg_id', $legA->id)->count());
    }

    /**
     * ADR-106 addendum (2026-09-21) — the exception-catch path
     * (Throwable mid-call) is a real, distinct write site from the
     * clean Pending/Failure/Success branches, and must not be missed:
     * a leg that throws still gets a durable attempt row, not just its
     * mutable `failure_reason` column overwritten.
     */
    public function test_a_leg_that_throws_unexpectedly_still_writes_a_durable_attempt_row(): void
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

        $adapter = new class implements SupplierAdapter
        {
            private int $calls = 0;

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
                $this->calls++;
                if ($this->calls === 2) {
                    throw new RuntimeException('Connection timed out');
                }

                return SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]);
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

        $result = $this->service($adapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);

        $legB = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->sole();
        $attempt = OrderResendAttempt::query()->where('order_delivery_leg_id', $legB->id)->sole();
        $this->assertSame('initial', $attempt->attempt_type);
        $this->assertSame('failed', $attempt->outcome);
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

    /**
     * ADR-103 decisions 3/4 — a Failed leg is confirmed non-delivered,
     * so a retry mints it a genuinely fresh reference (ULID-suffixed),
     * never reusing the one the failed attempt already used — mirrors
     * ADR-102 decision 9 at leg granularity.
     */
    public function test_retrying_a_failed_leg_mints_a_fresh_reference(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $firstAttemptAdapter = $this->queuedAdapter([
            SupplierResponse::failure('invalid_product', 'Bad product'),
        ]);
        $this->service($firstAttemptAdapter)->fulfill($order);

        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Failed, $leg->status);
        $firstReference = $leg->reference_number;
        $this->assertSame($order->fresh()->reference_number.'-L1', $firstReference);

        $capturedReferenceNumber = null;
        $retryAdapter = new class($capturedReferenceNumber) implements SupplierAdapter
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

                return SupplierResponse::success(['supplier_ref' => 'SREF-RETRY']);
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

        $this->service($retryAdapter)->fulfill($order->fresh());

        $this->assertNotSame($firstReference, $capturedReferenceNumber);
        $this->assertStringStartsWith($firstReference.'-', $capturedReferenceNumber);
        $this->assertSame($capturedReferenceNumber, $leg->fresh()->reference_number);
    }

    /**
     * ADR-103 decisions 2/3 — a NeedsReview leg reuses its stored
     * reference unchanged (the double-delivery protection), and only a
     * NeedsReview outcome ever writes the leg-level
     * `resend_unsafe_with_same_reference` flag — a plain Failed leg
     * never does, since decision 3 always gives it a fresh reference
     * regardless of that flag's value.
     */
    public function test_a_needs_review_leg_reuses_its_stored_reference_and_sets_the_unsafe_flag(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::failure('duplicate_reference', 'Already submitted', resendUnsafeWithSameReference: true),
        ]);
        $this->service($adapter)->fulfill($order);

        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::NeedsReview, $leg->status);
        $this->assertTrue($leg->resend_unsafe_with_same_reference);
        $storedReference = $leg->reference_number;

        $capturedReferenceNumber = null;
        $retryAdapter = new class($capturedReferenceNumber) implements SupplierAdapter
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

                return SupplierResponse::failure('duplicate_reference', 'Still ambiguous', resendUnsafeWithSameReference: true);
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

        $this->service($retryAdapter)->fulfill($order->fresh());

        $this->assertSame($storedReference, $capturedReferenceNumber);

        // ADR-103 decision 8 — the order-level OR-rollup now reflects
        // this leg's own unsafe flag, retiring the old combo branch
        // that read the (always-empty) Order.supplier_response instead.
        $this->assertTrue($order->fresh()->resendUnsafeToOverride());
    }

    /**
     * ADR-094 decision 7 (Phase 3b): a Digiflazz-shaped async leg lands
     * the whole order in Pending, exactly like the single-order path.
     */
    public function test_a_pending_leg_lands_the_whole_order_in_pending(): void
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
            SupplierResponse::pending(['status' => 'Pending']),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame(DeliveryStatus::Pending, $legs[1]->status);
    }

    /**
     * ADR-094 decision 7 (Phase 3b): finalizePendingDeliveryLeg() is the
     * per-leg counterpart to finalizePendingDelivery() — a webhook/poll
     * resolving the last still-Pending leg transitions the whole order
     * out of Pending, crediting profit exactly once.
     */
    public function test_finalizing_the_last_pending_leg_as_delivered_completes_the_order(): void
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
            SupplierResponse::pending(['status' => 'Pending']),
        ]);
        $pendingResult = $this->service($adapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::Pending, $pendingResult->delivery_status);

        $pendingLeg = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->firstOrFail();

        $finalResult = $this->service($this->queuedAdapter([]))->finalizePendingDeliveryLeg(
            $pendingLeg,
            SupplierOutcome::Success,
            'SREF-B-WEBHOOK',
            ['price' => 480],
        );

        $this->assertSame(DeliveryStatus::Delivered, $finalResult->delivery_status);
        $this->assertNotNull($finalResult->delivered_at);
        $this->assertSame('SREF-B-WEBHOOK', $pendingLeg->fresh()->supplier_reference);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->count());
        $this->assertSame(2, SupplierLedgerEntry::query()->count());
    }

    /**
     * A Pending leg resolving as Failed while its sibling already
     * Delivered is decision 9's partial-delivery case, same as the
     * synchronous path — just reached via webhook/poll instead.
     */
    public function test_finalizing_a_pending_leg_as_failed_lands_the_order_in_needs_review(): void
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
            SupplierResponse::pending(['status' => 'Pending']),
        ]);
        $this->service($adapter)->fulfill($order);

        $pendingLeg = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->firstOrFail();

        $finalResult = $this->service($this->queuedAdapter([]))->finalizePendingDeliveryLeg(
            $pendingLeg,
            SupplierOutcome::Failure,
            null,
            ['error_message' => 'Gagal'],
        );

        $this->assertSame(DeliveryStatus::NeedsReview, $finalResult->delivery_status);
        $this->assertSame(DeliveryStatus::Failed, $pendingLeg->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());
    }

    /**
     * ADR-103 decision 2 — the async (webhook/poll) finalize path also
     * writes the leg-level unsafe flag, same as the synchronous
     * attemptLeg() path, whenever the outcome is genuinely ambiguous.
     */
    public function test_finalizing_a_pending_leg_as_needs_review_sets_the_unsafe_flag(): void
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
            SupplierResponse::pending(['status' => 'Pending']),
        ]);
        $this->service($adapter)->fulfill($order);

        $pendingLeg = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->firstOrFail();

        $finalResult = $this->service($this->queuedAdapter([]))->finalizePendingDeliveryLeg(
            $pendingLeg,
            SupplierOutcome::Failure,
            null,
            ['error_message' => 'duplicate'],
            resendUnsafeWithSameReference: true,
            outcomeConfirmedFailed: false,
        );

        $this->assertSame(DeliveryStatus::NeedsReview, $finalResult->delivery_status);
        $this->assertSame(DeliveryStatus::NeedsReview, $pendingLeg->fresh()->status);
        $this->assertTrue($pendingLeg->fresh()->resend_unsafe_with_same_reference);
        $this->assertTrue($order->fresh()->resendUnsafeToOverride());
    }

    /**
     * A leg still stuck Pending after one resolves must leave the order
     * at Pending, not throw — resolveComboOutcome() re-evaluated from a
     * Pending entry state with an incomplete leg set is a no-op.
     */
    public function test_finalizing_one_of_two_pending_legs_leaves_the_order_pending(): void
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
            SupplierResponse::pending(['status' => 'Pending']),
            SupplierResponse::pending(['status' => 'Pending']),
        ]);
        $this->service($adapter)->fulfill($order);

        $legA = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 1)->firstOrFail();

        $result = $this->service($this->queuedAdapter([]))->finalizePendingDeliveryLeg(
            $legA,
            SupplierOutcome::Success,
            'SREF-A-WEBHOOK',
        );

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
    }

    /**
     * Same idempotency-by-construction guard finalizePendingDelivery()
     * relies on at the order level — a duplicate webhook delivery for
     * an already-finalized leg throws, never double-credits.
     */
    /**
     * ADR-107 decision 2 — platform_profit is reconciled to the
     * money-conservation residual on final delivery: order.selling_price
     * (frozen) − Σ live componentPackage->cost_price across every leg −
     * affiliate_profit. Both components here default to cost_price=500,
     * so live cost total = 1000; order fixture selling_price=1500,
     * affiliate_profit=0 -> expected platform_profit = 500 (overriding
     * the fixture's own hardcoded 200).
     */
    public function test_platform_profit_reconciles_to_the_money_conservation_residual_on_full_delivery(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 480]),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(500, $result->platform_profit);
        $this->assertSame(500, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sole()->amount);
    }

    /**
     * ADR-107 decision 2 — reconciliation reads LIVE cost at final
     * resolution, not the cost each leg had when it individually
     * delivered: leg B's component cost_price is bumped (simulating a
     * routine SyncSupplierPricesJob run) between the first attempt and
     * the retry that finally delivers it, and the platform absorbs the
     * difference in its own reported profit.
     */
    public function test_platform_profit_reconciliation_reflects_a_live_cost_change_between_attempts(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId); // cost_price 500
        $b = $this->componentPackage($supplier, $gameId); // cost_price 500
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $firstAttemptAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::failure('insufficient_balance', 'No balance'),
        ]);
        $firstResult = $this->service($firstAttemptAdapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::NeedsReview, $firstResult->delivery_status);

        // A price sync lands between the first attempt and the retry —
        // package B's real cost has genuinely risen.
        $b->update(['cost_price' => 700]);

        $retryAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-B-RETRY', 'price' => 700]),
        ]);
        $secondResult = $this->service($retryAdapter)->fulfill($firstResult->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $secondResult->delivery_status);
        // selling_price 1500 - (liveCost 500 + 700) - affiliate_profit 0 = 300
        $this->assertSame(300, $secondResult->platform_profit);
    }

    /**
     * ADR-107 decision 3, signal generalized by ADR-111 decision 7 — a
     * resulting loss is never blocked: the order still delivers, the
     * reconciled (negative) platform_profit is recorded as-is, and
     * Order::hasReconciledProfitFlag() (the Order Detail visibility
     * signal, universal now — not combo-only) becomes true. No
     * override-reason gate (unlike ADR-105 decision 4's synchronous-
     * admin-only mechanism, deliberately not reused here).
     */
    public function test_a_resulting_loss_never_blocks_delivery_and_flags_the_order(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 1000]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 1000]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        // selling_price 1500 stays below the 2000 total live cost.
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 1000]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 1000]),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(-500, $result->platform_profit);
        $this->assertTrue($result->hasReconciledProfitFlag());
        // The customer still got their goods, and the ledger still
        // credits whatever was reconciled — a real, logged loss, not a
        // silently clamped-to-zero one.
        $this->assertSame(-500, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sole()->amount);
    }

    /**
     * ADR-107 decision 2 — affiliate_profit is never touched by
     * reconciliation, even when the platform absorbs a real cost
     * increase: it keeps the exact value frozen at checkout.
     */
    public function test_affiliate_profit_is_never_reconciled_by_a_combo_retry(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 50, 'selling_price' => 900]);

        $adapter = $this->queuedAdapter([SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 500])]);
        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(50, $result->affiliate_profit);
        // selling_price 900 - liveCost 500 - affiliate_profit 50 = 350
        $this->assertSame(350, $result->platform_profit);
    }

    public function test_finalizing_an_already_delivered_leg_throws(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo);

        $this->service($this->queuedAdapter([SupplierResponse::success(['supplier_ref' => 'SREF'])]))->fulfill($order);
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->firstOrFail();

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->queuedAdapter([]))->finalizePendingDeliveryLeg(
            $leg,
            SupplierOutcome::Success,
            'SREF-DUPLICATE',
        );
    }

    /**
     * ADR-094's 2026-09-21 addendum decision 25 — isPartialComboDelivery()
     * now also recognizes a Delivered+NeedsReview mix (not just
     * Delivered+Failed) as a genuine partial delivery, since a leg can
     * land on NeedsReview for reasons decision 4 (ADR-102) never closed
     * (Gamevion duplicate_reference, an unexpected exception). Proven
     * here directly against the model, independent of the HTTP guard.
     */
    public function test_is_partial_combo_delivery_is_true_for_a_delivered_and_needs_review_leg_mix(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['delivery_status' => DeliveryStatus::NeedsReview->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-1',
            'selling_price_sen' => $a->standard_selling_price,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value,
            'failure_reason' => 'Duplicate reference', 'selling_price_sen' => $b->standard_selling_price,
        ]);

        $this->assertTrue($order->fresh()->isPartialComboDelivery());
    }

    /**
     * Guards decision 25's own scoping: a Failed+NeedsReview mix with NO
     * Delivered leg is NOT "partial" — nothing was delivered yet, so a
     * full-order Failed + full voucher is correct there, not
     * over-compensation. Only a Delivered leg alongside an
     * unresolved/Failed one triggers the carve-out.
     */
    public function test_is_partial_combo_delivery_is_false_with_no_delivered_leg_at_all(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['delivery_status' => DeliveryStatus::NeedsReview->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Failed->value,
            'failure_reason' => 'Insufficient balance', 'selling_price_sen' => $a->standard_selling_price,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value,
            'failure_reason' => 'Duplicate reference', 'selling_price_sen' => $b->standard_selling_price,
        ]);

        $this->assertFalse($order->fresh()->isPartialComboDelivery());
    }

    /**
     * ADR-094's 2026-09-21 addendum decision 26 — the real fix for the
     * TOCTOU race: OrderController::confirmFailed()'s own
     * isPartialComboDelivery() guard runs on the unlocked $order before
     * this service method's lock is even acquired, so a state change in
     * that gap (a concurrent retry/webhook delivering a leg) would
     * otherwise go unnoticed. Calling the service directly here (the
     * controller's own pre-check is bypassed entirely) proves the
     * service's own in-lock re-check is the real, final guard — not
     * just relying on the controller.
     */
    public function test_confirm_delivery_failed_rejects_a_partial_combo_delivery_order_even_when_called_directly(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['delivery_status' => DeliveryStatus::NeedsReview->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-1',
            'selling_price_sen' => $a->standard_selling_price,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value,
            'failure_reason' => 'Duplicate reference', 'selling_price_sen' => $b->standard_selling_price,
        ]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->queuedAdapter([]))->confirmDeliveryFailed($order, 'note', 'Jane Admin');
    }

    /**
     * ADR-111 decision 2 — the leg-scoped capture: attemptLeg()'s own
     * Success branch converts the supplier's real price into
     * `real_cost_price_sen`, unconditionally (regardless of the feature
     * flag — only resolveComboOutcome()'s own USE of it is gated).
     */
    public function test_attempt_leg_captures_real_cost_price_sen_on_delivery(): void
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
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 7.0]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 4.8]),
        ]);
        $this->service($adapter)->fulfill($order);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(700, $legs[0]->real_cost_price_sen);
        $this->assertSame(480, $legs[1]->real_cost_price_sen);
    }

    /**
     * ADR-111 decision 3/8: when the flag is enabled, resolveComboOutcome()
     * sums each leg's REAL cost instead of its catalog `cost_price` — and
     * falls back to that leg's own catalog cost only when ITS real cost
     * is genuinely missing (decision 6's per-delivery fallback), never
     * failing the whole reconciliation over one leg. Legs are pre-seeded
     * directly as already-Delivered so fulfill()'s combo loop skips
     * attemptLeg() entirely and goes straight to resolveComboOutcome() —
     * same direct-model pattern this file already uses for
     * isPartialComboDelivery() coverage above.
     */
    public function test_resolve_combo_outcome_uses_real_cost_with_per_leg_fallback_when_flag_enabled(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['selling_price' => 1500, 'affiliate_profit' => 0, 'platform_profit' => 100]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-1',
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-2',
            // real_cost_price_sen genuinely missing (FX unavailable at
            // capture time) — falls back to this leg's own catalog cost.
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => null,
        ]);

        $result = $this->service($this->queuedAdapter([]))->fulfill($order);

        // costTotal = 700 (real, leg A) + 500 (catalog fallback, leg B) = 1200
        // platformProfit = 1500 - 1200 - 0 = 300; drift from the 100 estimate = 200 (> RM1 AND > 1% of 1500).
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(300, $result->platform_profit);
        $this->assertTrue($result->profit_reconciled_flagged);
    }
}
