<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComboFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-094 decisions 7-11, 18-20 (Phase 3, core — synchronous legs
 * only; Digiflazz's async Pending-then-webhook per-leg resolution is a
 * separate follow-up phase, not built here).
 *
 * This file: pending legs, finalizing, confirm-failed and partial settlement.
 */
class OrderFulfillmentServiceComboSettlementTest extends TestCase
{
    use ComboFulfillmentFixtures;
    use RefreshDatabase;

    /** ADR-094 decision 28 — a known mixed outcome is its own terminal status, not NeedsReview. */
    public function test_one_leg_failing_lands_the_order_in_partially_delivered_when_the_other_succeeded(): void
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

        $this->assertSame(DeliveryStatus::PartiallyDelivered, $result->delivery_status);
        $this->assertNull($result->delivered_at);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame(DeliveryStatus::Failed, $legs[1]->status);

        // No profit credited yet — decision 35: credited at settlement, like Failed.
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());
        // The succeeded leg's own drawdown is still recorded — it's real.
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
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
    public function test_finalizing_a_pending_leg_as_failed_lands_the_order_in_partially_delivered(): void
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

        $this->assertSame(DeliveryStatus::PartiallyDelivered, $finalResult->delivery_status);
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
     * ADR-094 decision 31 — Confirm Failed on a combo flips the ambiguous
     * legs to Failed and rolls up: a Delivered leg makes it PartiallyDelivered.
     */
    public function test_confirm_delivery_failed_on_a_combo_with_a_delivered_leg_lands_in_partially_delivered(): void
    {
        $order = $this->needsReviewComboWithLegs([DeliveryStatus::Delivered, DeliveryStatus::NeedsReview]);

        $result = $this->service($this->queuedAdapter([]))->confirmDeliveryFailed($order, 'checked supplier dashboard', 'Jane Admin');

        $this->assertSame(DeliveryStatus::PartiallyDelivered, $result->delivery_status);
        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(DeliveryStatus::Delivered, $legs[0]->status);
        $this->assertSame(DeliveryStatus::Failed, $legs[1]->status);
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());
    }

    public function test_confirm_delivery_failed_on_a_combo_with_no_delivered_leg_lands_in_failed(): void
    {
        $order = $this->needsReviewComboWithLegs([DeliveryStatus::Failed, DeliveryStatus::NeedsReview]);

        $result = $this->service($this->queuedAdapter([]))->confirmDeliveryFailed($order, 'note', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(0, OrderDeliveryLeg::query()->where('order_id', $order->id)->where('status', '!=', DeliveryStatus::Failed->value)->count());
    }

    public function test_confirm_delivery_failed_refuses_a_combo_with_a_leg_still_pending(): void
    {
        $order = $this->needsReviewComboWithLegs([DeliveryStatus::Pending, DeliveryStatus::NeedsReview]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->queuedAdapter([]))->confirmDeliveryFailed($order, 'note', 'Jane Admin');
    }

    /**
     * ADR-094 decisions 28-35, the whole lifecycle in one pass: a real
     * fulfilment lands on PartiallyDelivered, settlement compensates the
     * undelivered share and credits the delivered leg's profit once, and
     * after that no retry can deliver on top of the compensation.
     */
    public function test_a_partial_delivery_settles_once_and_then_refuses_any_further_delivery(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 2400, 'standard_selling_price' => 3000]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 1700, 'standard_selling_price' => 2000]);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1], ['package' => $b, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo, [
            'customer_phone' => '60123456789',
            'selling_price' => 5000, 'transaction_fee' => 100, 'final_amount' => 5100, 'affiliate_profit' => 0,
        ]);

        $result = $this->service($this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A']),
            SupplierResponse::failure('insufficient_balance', 'No balance'),
        ]))->fulfill($order);
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $result->delivery_status);
        $this->assertTrue($result->delivery_status->isCompensable());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());

        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $settled = app(OrderSettlementService::class)->settle($result, $admin->id);

        // u = 2000/5000: voucher = 5000 cash × 0.4; profit = (5000 − 2000) − 2400.
        $this->assertSame(2000, $settled->voucher?->amount);
        $this->assertSame(600, (int) LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $order->fresh()->delivery_status);

        // A retry after compensation never reaches the supplier.
        $neverCalled = $this->queuedAdapter([]);
        try {
            $this->service($neverCalled)->fulfill($order->fresh());
            $this->fail('A compensated partial order must refuse further delivery.');
        } catch (OrderFulfillmentException) {
        }
        $this->assertSame(DeliveryStatus::Failed, OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->value('status'));
        $this->assertSame(2, LedgerEntry::query()->where('type', 'order_profit')->count());
        // Item 79: a compensation voucher never debits the platform ledger.
        $this->assertSame(0, LedgerEntry::query()->where('type', 'voucher_issued')->count());
    }

    /** ADR-094 decision 29 — Retry from PartiallyDelivered re-attempts the failed leg only. */
    public function test_a_partially_delivered_order_can_be_retried_to_full_delivery(): void
    {
        $order = $this->needsReviewComboWithLegs([DeliveryStatus::Delivered, DeliveryStatus::Failed]);
        $order->update(['delivery_status' => DeliveryStatus::PartiallyDelivered->value, 'reference_number' => 'PGREF-1']);

        $result = $this->service($this->queuedAdapter([SupplierResponse::success(['supplier_ref' => 'SREF-B'])]))->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(2, LedgerEntry::query()->where('type', 'order_profit')->count());
    }
}
