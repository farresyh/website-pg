<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\ComboFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-094 decisions 7-11, 18-20 (Phase 3, core — synchronous legs
 * only; Digiflazz's async Pending-then-webhook per-leg resolution is a
 * separate follow-up phase, not built here).
 *
 * This file: leg delivery and each outcome (delivered, failed, pending, needs review).
 */
class OrderFulfillmentServiceComboTest extends TestCase
{
    use ComboFulfillmentFixtures;
    use RefreshDatabase;

    public function test_an_ambiguous_digiflazz_leg_is_parked_at_pending_and_polled(): void
    {
        Queue::fake();
        [$order, $service] = $this->digiflazzCombo([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            SupplierResponse::failure('CIRCUIT_OPEN', 'breaker open', resendUnsafeWithSameReference: true),
        ]);

        $result = $service->fulfill($order);

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $this->assertSame(DeliveryStatus::Pending, OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->value('status'));
        Queue::assertPushed(CheckSupplierDeliveryJob::class, fn ($job) => $job->order->id === $order->id && $job->delay !== null);
    }

    public function test_a_digiflazz_leg_that_throws_is_parked_at_pending_with_its_reference_kept(): void
    {
        Queue::fake();
        [$order, $service] = $this->digiflazzCombo([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 480]),
            new ConnectionException('Connection timed out'),
        ]);

        $this->assertSame(DeliveryStatus::Pending, $service->fulfill($order)->delivery_status);
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->firstOrFail();
        $this->assertSame(DeliveryStatus::Pending, $leg->status);
        $this->assertNotNull($leg->reference_number);
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
        // decision 28's PartiallyDelivered.
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $result->delivery_status);
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
}
