<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-1',
            'reference_number' => null,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_persists_and_casts_payment_and_delivery_status_as_enums(): void
    {
        $order = $this->makeOrder();

        $fresh = Order::query()->findOrFail($order->id);

        $this->assertSame(PaymentStatus::Pending, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $fresh->delivery_status);
    }

    /**
     * This is the whole point of the Order model existing: proves
     * ReferenceNumberService (ORD-8) actually has somewhere to persist
     * its idempotency guarantee, rather than being a service with no
     * caller.
     */
    public function test_reference_number_is_generated_once_and_reused_on_retry(): void
    {
        $order = $this->makeOrder(['reference_number' => null]);
        $service = new ReferenceNumberService;

        $firstAttempt = $service->resolve($order->reference_number);
        $order->update(['reference_number' => $firstAttempt]);

        $reloaded = Order::query()->findOrFail($order->id);
        $secondAttempt = $service->resolve($reloaded->reference_number);

        $this->assertSame($firstAttempt, $secondAttempt);
        $this->assertStringStartsWith('REF-', $reloaded->reference_number);
    }

    /**
     * Proves OrderStatusService's transitions (ORD-11 guard) actually
     * drive persisted Order state, not just in-memory enum values.
     */
    public function test_order_status_service_transition_persists_through_the_model(): void
    {
        $order = $this->makeOrder([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $service = new OrderStatusService;
        $newStatus = $service->startDelivery($order->payment_status, $order->delivery_status);
        $order->update(['delivery_status' => $newStatus->value]);

        $reloaded = Order::query()->findOrFail($order->id);

        $this->assertSame(DeliveryStatus::Processing, $reloaded->delivery_status);
    }

    public function test_supplier_response_is_cast_to_array(): void
    {
        $order = $this->makeOrder([
            'supplier_ref' => 'GV-RAPI-1A2B3C4D5E6F',
            'supplier_response' => ['invoice_number' => 'GV-RAPI-1A2B3C4D5E6F', 'game' => 'Free Fire'],
        ]);

        $reloaded = Order::query()->findOrFail($order->id);

        $this->assertSame('Free Fire', $reloaded->supplier_response['game']);
    }

    private ?Supplier $comboSupplier = null;

    private ?Game $comboGame = null;

    private function componentPackage(array $overrides = []): Package
    {
        $this->comboSupplier ??= Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $this->comboGame ??= Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $supplier = $this->comboSupplier;
        $game = $this->comboGame;

        return Package::query()->create(array_merge([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ], $overrides));
    }

    private function leg(Order $order, Package $component, DeliveryStatus $status, int $legNumber, ?bool $resendUnsafeWithSameReference = null): OrderDeliveryLeg
    {
        return OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id,
            'supplier_id' => $component->supplier_id, 'leg_number' => $legNumber, 'status' => $status->value,
            'resend_unsafe_with_same_reference' => $resendUnsafeWithSameReference,
            // ADR-107 decision 1 — mirrors what seedDeliveryLegs() itself
            // freezes at checkout, so this fixture matches real behavior.
            'selling_price_sen' => $component->standard_selling_price,
        ]);
    }

    /**
     * ADR-094 decision 9 (Phase 4): the actual partial-delivery case —
     * a real Delivered+Failed split, nothing Pending/NeedsReview left.
     *
     * ADR-107 decision 4 (build-time revision): the suggested amount is
     * now `(final_amount − transaction_fee)` apportioned by each leg's
     * frozen `selling_price_sen` weight, not a live sum of the Failed
     * leg's own component price. makeOrder()'s defaults give
     * compensableAmount=1000; delivered leg carries selling_price_sen
     * 44000, failed leg 27500 (total 71500) — 1000 × 27500/71500 ≈
     * 384.6, rounds to 385.
     */
    public function test_is_partial_combo_delivery_true_for_a_genuine_delivered_and_failed_split(): void
    {
        $delivered = $this->componentPackage();
        $failed = $this->componentPackage([
            'name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976',
            'cost_price' => 25000, 'standard_selling_price' => 27500,
        ]);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $failed, DeliveryStatus::Failed, 2);

        $this->assertTrue($order->isPartialComboDelivery());
        $this->assertSame(385, $order->suggestedPartialVoucherAmount());
    }

    /**
     * ADR-107 decision 4 — proportional, not a flat sum: two Failed legs
     * of different weight get apportioned differently, and the total
     * never exceeds compensableAmount regardless of the legs' own
     * (unrelated-scale) selling prices.
     */
    public function test_suggested_partial_voucher_amount_apportions_proportionally_across_multiple_failed_legs(): void
    {
        $delivered = $this->componentPackage(['standard_selling_price' => 20000]);
        $failedSmall = $this->componentPackage(['name' => 'Small', 'supplier_package_ref' => 'GV-SMALL', 'standard_selling_price' => 10000]);
        $failedLarge = $this->componentPackage(['name' => 'Large', 'supplier_package_ref' => 'GV-LARGE', 'standard_selling_price' => 30000]);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value, 'final_amount' => 2090]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $failedSmall, DeliveryStatus::Failed, 2);
        $this->leg($order, $failedLarge, DeliveryStatus::Failed, 3);

        // compensableAmount = 2090 - 90 = 2000; failed weight = 10000+30000=40000
        // of total 60000 -> 2000 * 40000/60000 = 1333.33 -> rounds to 1333.
        $this->assertSame(1333, $order->suggestedPartialVoucherAmount());
    }

    /** A leg missing its frozen snapshot (pre-ADR-107 legacy row) never yields a divide-by-zero or a silently-wrong figure — no suggestion instead. */
    public function test_suggested_partial_voucher_amount_null_when_no_leg_has_a_frozen_selling_price(): void
    {
        $delivered = $this->componentPackage();
        $failed = $this->componentPackage(['name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976']);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id,
            'supplier_id' => $delivered->supplier_id, 'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $failed->id,
            'supplier_id' => $failed->supplier_id, 'leg_number' => 2, 'status' => DeliveryStatus::Failed->value,
        ]);

        $this->assertNull($order->suggestedPartialVoucherAmount());
    }

    /**
     * ADR-094's 2026-09-21 addendum decision 25 — a leg-level NeedsReview
     * (e.g. a Gamevion duplicate_reference) alongside a Delivered leg IS
     * now recognized as a genuine partial delivery, same carve-out as a
     * Delivered+Failed split: ADR-102 decision 4 only ever closed the
     * Digiflazz-confirmed-Gagal cause of a leg landing on NeedsReview,
     * this one (and an unexpected exception mid-attempt) were never
     * covered — without this, confirmFailed() would let an admin
     * confirm the whole order Failed and over-compensate with a
     * full-amount voucher despite the delivered leg's goods already
     * being received. Same math as the Delivered+Failed test above (385)
     * — the numerator now includes NeedsReview legs too.
     */
    public function test_is_partial_combo_delivery_true_when_a_delivered_leg_is_mixed_with_an_ambiguous_one(): void
    {
        $delivered = $this->componentPackage();
        $ambiguous = $this->componentPackage([
            'name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976',
            'cost_price' => 25000, 'standard_selling_price' => 27500,
        ]);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $ambiguous, DeliveryStatus::NeedsReview, 2);

        $this->assertTrue($order->isPartialComboDelivery());
        $this->assertSame(385, $order->suggestedPartialVoucherAmount());
    }

    /**
     * Guards decision 25's own scoping so it doesn't over-block: a
     * Failed+NeedsReview mix with NO Delivered leg is NOT "partial" —
     * nothing was delivered yet, so confirming the whole order Failed
     * (and a later full-amount voucher) stays correct there.
     */
    public function test_is_partial_combo_delivery_false_when_no_leg_is_delivered_yet(): void
    {
        $failed = $this->componentPackage();
        $ambiguous = $this->componentPackage(['name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976']);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $failed, DeliveryStatus::Failed, 1);
        $this->leg($order, $ambiguous, DeliveryStatus::NeedsReview, 2);

        $this->assertFalse($order->isPartialComboDelivery());
        $this->assertNull($order->suggestedPartialVoucherAmount());
    }

    /** A leg still Pending is still in flight — not a resolved partial delivery yet. */
    public function test_is_partial_combo_delivery_false_while_a_leg_is_still_pending(): void
    {
        $delivered = $this->componentPackage();
        $pending = $this->componentPackage(['name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976']);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $pending, DeliveryStatus::Pending, 2);

        $this->assertFalse($order->isPartialComboDelivery());
    }

    /** A non-combo order (no legs at all) is never a partial-combo-delivery case. */
    public function test_is_partial_combo_delivery_false_for_an_ordinary_order_with_no_legs(): void
    {
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->assertFalse($order->isPartialComboDelivery());
        $this->assertNull($order->suggestedPartialVoucherAmount());
    }

    /** Every leg Delivered means the order itself wouldn't be NeedsReview in practice, but the method's own guard is the delivery_status check, not the leg shape. */
    public function test_is_partial_combo_delivery_false_when_delivery_status_is_not_needs_review(): void
    {
        $delivered = $this->componentPackage();
        $failed = $this->componentPackage(['name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976']);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::Failed->value]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $failed, DeliveryStatus::Failed, 2);

        $this->assertFalse($order->isPartialComboDelivery());
    }

    /** ADR-026 addendum (2026-09-16), renamed by ADR-102 decision 3/5 — the same generic signal ADR-098 wired into fulfillment, reconstructed from the persisted error_code alone. */
    public function test_delivery_retry_unsafe_with_same_reference_true_for_a_digiflazz_terminal_rc(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $this->assertTrue($order->deliveryRetryUnsafeWithSameReference());
    }

    public function test_delivery_retry_unsafe_with_same_reference_false_for_a_digiflazz_retriable_rc(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'supplier_response' => ['error_code' => '44', 'error_message' => 'Saldo tidak cukup'],
        ]);

        $this->assertFalse($order->deliveryRetryUnsafeWithSameReference());
    }

    public function test_delivery_retry_unsafe_with_same_reference_true_for_gamevion_duplicate_reference(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'supplier_response' => ['error_code' => 'duplicate_reference', 'error_message' => 'dup'],
        ]);

        $this->assertTrue($order->deliveryRetryUnsafeWithSameReference());
    }

    /** Collision-safety: a non-Digiflazz order sharing a Digiflazz rc string must never be flagged. */
    public function test_delivery_retry_unsafe_with_same_reference_false_for_a_non_digiflazz_order_sharing_a_digiflazz_rc_string(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Some unrelated Gamevion error'],
        ]);

        $this->assertFalse($order->deliveryRetryUnsafeWithSameReference());
    }

    public function test_delivery_retry_unsafe_with_same_reference_false_when_no_error_code_is_recorded(): void
    {
        $order = $this->makeOrder();

        $this->assertFalse($order->deliveryRetryUnsafeWithSameReference());
    }

    /**
     * ADR-102 decision 3 — the SCOPED rule: a non-combo order only
     * disables from NeedsReview. A Failed non-combo order is NEVER
     * scoped-unsafe, even when the raw signal is true, since decision
     * 9 always regenerates its reference on resend — consulting the
     * flag there would wrongly disable a perfectly resendable order.
     */
    public function test_resend_unsafe_to_override_false_for_a_non_combo_failed_order_even_when_the_raw_signal_is_true(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $this->assertTrue($order->deliveryRetryUnsafeWithSameReference());
        $this->assertFalse($order->resendUnsafeToOverride());
    }

    public function test_resend_unsafe_to_override_true_for_a_non_combo_needs_review_order_with_the_raw_signal_true(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $order = $this->makeOrder([
            'supplier_id' => $supplier->id,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => 'duplicate_reference', 'error_message' => 'dup'],
        ]);

        $this->assertTrue($order->resendUnsafeToOverride());
    }

    /**
     * ADR-103 decision 8 — a combo order's own "combo" branch is
     * retired: it no longer consults Order.supplier_response (always
     * empty for a real combo order anyway, ADR-094 decision 3) at all.
     * Instead it's an OR-rollup across legs' own
     * resend_unsafe_with_same_reference flag, checked only for a leg
     * currently NeedsReview — a Failed leg is never genuinely futile
     * (decision 3 always mints it a fresh reference on retry), so this
     * stays false even with a stale/irrelevant Order-level signal set.
     */
    public function test_resend_unsafe_to_override_false_for_a_combo_failed_order_regardless_of_the_stale_order_level_signal(): void
    {
        $component = $this->componentPackage();
        $order = $this->makeOrder([
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => 'duplicate_reference', 'error_message' => 'dup'],
        ]);
        $this->leg($order, $component, DeliveryStatus::Failed, 1);

        $this->assertFalse($order->resendUnsafeToOverride());
    }

    public function test_resend_unsafe_to_override_true_for_a_combo_order_with_a_needs_review_leg_flagged_unsafe(): void
    {
        $component = $this->componentPackage();
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $component, DeliveryStatus::NeedsReview, 1, resendUnsafeWithSameReference: true);

        $this->assertTrue($order->resendUnsafeToOverride());
    }

    public function test_resend_unsafe_to_override_false_for_a_combo_order_with_a_needs_review_leg_not_flagged_unsafe(): void
    {
        $component = $this->componentPackage();
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        // A NeedsReview leg reached via an unexpected exception
        // (OrderFulfillmentService::attemptLeg()'s catch block) never
        // sets the flag — genuinely worth retrying, not confirmed
        // futile.
        $this->leg($order, $component, DeliveryStatus::NeedsReview, 1, resendUnsafeWithSameReference: null);

        $this->assertFalse($order->resendUnsafeToOverride());
    }

    /**
     * One click retries every outstanding leg together — a second,
     * unflagged Delivered leg alongside a genuinely unsafe NeedsReview
     * one must not mask the rollup.
     */
    public function test_resend_unsafe_to_override_true_when_only_one_of_several_legs_is_flagged_unsafe(): void
    {
        $componentA = $this->componentPackage(['supplier_package_ref' => 'GV-4810-A']);
        $componentB = $this->componentPackage(['supplier_package_ref' => 'GV-4810-B']);
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->leg($order, $componentA, DeliveryStatus::Delivered, 1);
        $this->leg($order, $componentB, DeliveryStatus::NeedsReview, 2, resendUnsafeWithSameReference: true);

        $this->assertTrue($order->resendUnsafeToOverride());
    }
}
