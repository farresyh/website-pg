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
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\ComboFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-094 decisions 7-11, 18-20 (Phase 3, core — synchronous legs
 * only; Digiflazz's async Pending-then-webhook per-leg resolution is a
 * separate follow-up phase, not built here).
 *
 * This file: retries, attempt rows and reference reuse.
 */
class OrderFulfillmentServiceComboRetryTest extends TestCase
{
    use ComboFulfillmentFixtures;
    use RefreshDatabase;

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
        // One leg Delivered + one Failed rolls the order up to
        // PartiallyDelivered (decision 28) — the leg itself is still plainly
        // Failed, which is what matters for this test.
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $order->fresh()->delivery_status);
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
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $firstResult->delivery_status);

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
}
