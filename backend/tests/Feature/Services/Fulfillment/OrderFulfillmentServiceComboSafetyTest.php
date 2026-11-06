<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\SupplierDeliveryCheckService;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ComboFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-103 2026-11-07 addendum (PRD §16 item 167): a leg's outcome-unknown
 * state is durable, a webhook can't decide an order mid-pass, and a dead
 * pass has one way out. The real SIGKILL proof is in tests/Concurrency.
 */
class OrderFulfillmentServiceComboSafetyTest extends TestCase
{
    use ComboFulfillmentFixtures;
    use RefreshDatabase;

    private SupplierAdapter $adapter;

    /** @var list<SupplierOrderRequest> */
    private array $sent = [];

    /** @var list<string> */
    private array $asked = [];

    private ?Closure $onCreate = null;

    private function combo(int $legs = 2, string $slug = 'digiflazz'): array
    {
        $supplier = Supplier::query()->create(['name' => $slug, 'slug' => $slug, 'api_config' => [], 'currency' => 'MYR']);
        $test = $this;
        $this->adapter = new class($test) implements SupplierAdapter
        {
            public function __construct(private readonly OrderFulfillmentServiceComboSafetyTest $test) {}

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return $this->test->supplierCall($request);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return $this->test->supplierAsked($request);
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used');
            }
        };
        $this->app->bind("supplier-adapter.{$slug}", fn () => $this->adapter);

        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $parts = [];

        for ($i = 0; $i < $legs; $i++) {
            $parts[] = ['package' => $this->componentPackage($supplier, $gameId), 'quantity' => 1];
        }

        return [$this->paidComboOrder($this->comboPackage($gameId, $parts)), $this->service($this->queuedAdapter([]))];
    }

    public function supplierCall(SupplierOrderRequest $request): SupplierResponse
    {
        $this->sent[] = $request;

        return ($this->onCreate)(count($this->sent), $request);
    }

    public function supplierAsked(SupplierStatusCheckRequest $request): SupplierResponse
    {
        $this->asked[] = $request->supplierRef;

        return SupplierResponse::failure('x', 'still unknown');
    }

    /** The order a killed worker leaves: Processing, with these legs. */
    private function deadPass(Order $order, array $statuses): Order
    {
        $order->update(['reference_number' => 'REF-DEAD', 'delivery_status' => DeliveryStatus::Processing->value]);
        $components = $order->package->components->values();

        foreach ($statuses as $i => $status) {
            $sent = $status !== DeliveryStatus::NotStarted;
            $leg = OrderDeliveryLeg::query()->create([
                'order_id' => $order->id, 'component_package_id' => $components[$i]->id,
                'supplier_id' => $components[$i]->supplier_id, 'leg_number' => $i + 1, 'status' => $status->value,
                'reference_number' => $sent ? 'REF-DEAD-L'.($i + 1) : null,
                'selling_price_sen' => 750,
                'delivered_at' => $status === DeliveryStatus::Delivered ? now() : null,
            ]);

            if ($status === DeliveryStatus::Processing) {
                OrderResendAttempt::query()->create([
                    'order_id' => $order->id, 'order_delivery_leg_id' => $leg->id, 'attempt_type' => 'initial',
                    'package_id' => $components[$i]->id, 'cost_price_sen' => 500, 'standard_selling_price_sen' => 600, 'outcome' => 'pending',
                ]);
            }
        }

        return $order->fresh();
    }

    private function legs(Order $order): array
    {
        return OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get()
            ->map(fn (OrderDeliveryLeg $leg) => $leg->status->value)->all();
    }

    public function test_the_claim_is_on_the_leg_before_the_supplier_is_called(): void
    {
        [$order, $service] = $this->combo(1);
        $seen = null;
        $this->onCreate = function (int $n, SupplierOrderRequest $request) use ($order, &$seen) {
            $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->first();
            $seen = [$leg->status, $leg->reference_number === $request->referenceNumber, OrderResendAttempt::query()->where('order_delivery_leg_id', $leg->id)->value('outcome')];

            return SupplierResponse::success(['supplier_ref' => 'SN']);
        };

        $service->fulfill($order);

        $this->assertSame([DeliveryStatus::Processing, true, 'pending'], $seen);
        $this->assertSame(['success'], OrderResendAttempt::query()->where('order_id', $order->id)->pluck('outcome')->all());
        $this->assertSame(['initial'], OrderResendAttempt::query()->where('order_id', $order->id)->pluck('attempt_type')->all());
    }

    public function test_a_retried_failed_leg_claims_its_fresh_reference_before_the_call(): void
    {
        [$order, $service] = $this->combo(1);
        $this->onCreate = fn () => SupplierResponse::failure('02', 'Transaksi Gagal');
        $service->fulfill($order);
        $old = OrderDeliveryLeg::query()->where('order_id', $order->id)->value('reference_number');

        $seen = null;
        $this->onCreate = function (int $n, SupplierOrderRequest $request) use ($order, &$seen) {
            $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->first();
            $seen = [$leg->status, $leg->reference_number];

            return SupplierResponse::success(['supplier_ref' => 'SN']);
        };

        $service->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Processing, $seen[0]);
        $this->assertSame($this->sent[1]->referenceNumber, $seen[1]);
        $this->assertNotSame($old, $seen[1]);
        $this->assertFalse($this->sent[1]->resubmit);
    }

    public function test_a_webhook_during_the_pass_cannot_decide_the_order(): void
    {
        [$order, $service] = $this->combo();
        $during = null;
        $this->onCreate = function (int $n) use ($order, $service, &$during) {
            if ($n === 2) {
                $leg1 = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 1)->first();
                $service->finalizePendingDeliveryLeg($leg1, SupplierOutcome::Success, 'SN1');
                $during = $order->fresh()->delivery_status;
            }

            return SupplierResponse::pending(['supplier_ref' => 'X']);
        };

        $service->fulfill($order);

        $this->assertSame(DeliveryStatus::Processing, $during);
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);

        $leg2 = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->first();
        $service->finalizePendingDeliveryLeg($leg2, SupplierOutcome::Success, 'SN2');

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $this->assertSame(['delivered', 'delivered'], $this->legs($order));
        $this->assertNotNull($order->fresh()->recognised_at);
    }

    public function test_a_roll_up_on_a_finished_order_changes_nothing(): void
    {
        [$order, $service] = $this->combo(1);
        $this->onCreate = fn () => SupplierResponse::pending(['supplier_ref' => 'X']);
        $service->fulfill($order);
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->first();
        $service->finalizePendingDeliveryLeg($leg, SupplierOutcome::Success, 'SN');
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);

        // What a slow pass's own end runs after a webhook already finished it.
        (new ReflectionMethod($service, 'resolveComboOutcome'))->invoke($service, $order->fresh());
        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $this->assertSame(1, LedgerEntry::query()->where('reference_id', $order->id)->where('owner_type', 'platform')->count());
    }

    public function test_a_dead_pass_with_every_leg_delivered_delivers_and_credits_profit(): void
    {
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Delivered]);

        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $this->assertNotNull($order->fresh()->recognised_at);
        $this->assertSame(1, LedgerEntry::query()->where('reference_id', $order->id)->where('owner_type', 'platform')->count());
    }

    public function test_a_dead_pass_parks_the_leg_in_flight_with_its_own_reference_and_polls(): void
    {
        Queue::fake();
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Processing]);

        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertSame(['delivered', 'pending'], $this->legs($order));
        $this->assertSame('REF-DEAD-L2', OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->value('reference_number'));
        Queue::assertPushed(CheckSupplierDeliveryJob::class);
        $this->assertSame('pending', OrderResendAttempt::query()->where('order_id', $order->id)->value('outcome'));
    }

    public function test_a_dead_pass_with_a_never_tried_leg_waits_for_an_admin(): void
    {
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::NotStarted]);

        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);

        // Retry sends only the never-tried leg, and that finishes the order.
        $this->onCreate = fn () => SupplierResponse::success(['supplier_ref' => 'SN']);
        $service->fulfill($order->fresh());

        $this->assertCount(1, $this->sent);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_parked_leg_beside_a_never_tried_leg_is_polled_then_waits_for_an_admin(): void
    {
        Queue::fake();
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Processing, DeliveryStatus::NotStarted]);

        $service->recoverDeadComboPass($order->fresh());
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);

        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 1)->first();
        $service->finalizePendingDeliveryLeg($leg, SupplierOutcome::Success, 'SN');

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
        $this->assertSame(['delivered', 'not_started'], $this->legs($order));
    }

    public function test_a_dead_pass_on_a_supplier_that_cannot_replay_goes_to_review_keeping_the_reference(): void
    {
        [$order, $service] = $this->combo(2, self::DEFAULT_SUPPLIER_SLUG);
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Processing]);

        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
        $this->assertSame(['delivered', 'needs_review'], $this->legs($order));
        $this->assertSame('REF-DEAD-L2', OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->value('reference_number'));
    }

    public function test_a_dead_pass_with_only_final_legs_settles_as_they_read(): void
    {
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Failed]);

        $service->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::PartiallyDelivered, $order->fresh()->delivery_status);
    }

    public function test_the_reconciler_recovers_a_stale_combo(): void
    {
        Queue::fake();
        [$order] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Processing]);
        Order::query()->whereKey($order->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertSame(['delivered', 'pending'], $this->legs($order));
    }

    public function test_confirm_failed_refuses_a_leg_still_at_processing(): void
    {
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::Processing]);
        $order->update(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->expectException(OrderFulfillmentException::class);

        $service->confirmDeliveryFailed($order->fresh(), 'note', 'Admin');
    }

    public function test_confirm_failed_marks_a_truly_unsent_leg_failed_without_asking_the_supplier(): void
    {
        [$order, $service] = $this->combo();
        $this->deadPass($order, [DeliveryStatus::Delivered, DeliveryStatus::NotStarted]);
        $service->recoverDeadComboPass($order->fresh());

        (new SupplierDeliveryCheckService($this->app->make(SupplierAdapterFactory::class), $service))
            ->confirmFailed($order->fresh(), 'note', 'Admin');

        $this->assertSame([], $this->asked);
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $order->fresh()->delivery_status);
    }

    public function test_a_combo_with_no_legs_never_reads_as_delivered(): void
    {
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $order = $this->paidComboOrder($this->comboPackage($gameId, []));

        $this->service($this->queuedAdapter([]))->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
        $this->assertSame(0, LedgerEntry::query()->where('reference_id', $order->id)->count());
    }

    public function test_a_combo_past_the_window_sends_nothing_even_for_a_failed_leg(): void
    {
        [$order, $service] = $this->combo(1);
        $this->onCreate = fn () => SupplierResponse::failure('02', 'Transaksi Gagal');
        $service->fulfill($order);
        Order::query()->whereKey($order->id)->update(['created_at' => now()->subDays(200)]);

        try {
            $service->fulfill($order->fresh());
            $this->fail('A past-window combo must not start a pass.');
        } catch (OrderFulfillmentException) {
            $this->assertCount(1, $this->sent);
            $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
            $this->assertSame(['failed'], $this->legs($order));
        }
    }

    public function test_an_exception_mid_call_parks_the_leg_and_keeps_its_attempt_row(): void
    {
        Queue::fake();
        [$order, $service] = $this->combo(1);
        $this->onCreate = fn () => throw new RuntimeException('connection reset');

        $service->fulfill($order);

        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->first();
        $this->assertSame(DeliveryStatus::Pending, $leg->status);
        $this->assertSame($this->sent[0]->referenceNumber, $leg->reference_number);
        $attempt = OrderResendAttempt::query()->where('order_delivery_leg_id', $leg->id)->sole();
        $this->assertSame(['initial', 'pending'], [$attempt->attempt_type, $attempt->outcome]);
        $this->assertSame(OrderFulfillmentService::AUTO_RECOVERY_NOTE, $attempt->supplier_response['auto_recovery']);
    }
}
