<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
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
    /** 2026-09-29 audit: a Failed/Pending event read the order before a Paid webhook committed. */
    public function test_set_payment_status_unless_paid_never_overwrites_a_paid_row(): void
    {
        $stale = $this->makeOrder();
        Order::query()->whereKey($stale->id)->update(['payment_status' => PaymentStatus::Paid->value]);

        $this->assertFalse($stale->setPaymentStatusUnlessPaid(PaymentStatus::Failed));
        $this->assertSame(PaymentStatus::Paid, $stale->fresh()->payment_status);
    }

    public function test_set_payment_status_unless_paid_writes_when_not_paid(): void
    {
        $order = $this->makeOrder();

        $this->assertTrue($order->setPaymentStatusUnlessPaid(PaymentStatus::Failed));
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

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

    /** ADR-094 decision 30 — Need Action covers an uncompensated partial delivery, like a Failed one. */
    public function test_needs_action_includes_an_uncompensated_partially_delivered_order(): void
    {
        $order = $this->makeOrder(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::PartiallyDelivered->value]);

        $this->assertTrue(Order::query()->needsAction()->whereKey($order->id)->exists());
    }

    /** ADR-094 decision 35 — a settled partial order's cost covers only its delivered legs, out of what it kept. */
    public function test_cost_basis_of_a_settled_partial_combo_counts_only_the_delivered_legs(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $delivered = $this->componentPackage();
        $failed = $this->componentPackage(['name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'GV-2976', 'cost_price' => 25000, 'standard_selling_price' => 27500]);
        $order = $this->makeOrder([
            'delivery_status' => DeliveryStatus::PartiallyDelivered->value,
            'selling_price' => 71500, 'affiliate_profit' => 0,
            // (71500 − 27500 compensated) − 40000 delivered cost
            'platform_profit' => 4000,
        ]);
        $this->leg($order, $delivered, DeliveryStatus::Delivered, 1);
        $this->leg($order, $failed, DeliveryStatus::Failed, 2);
        Voucher::query()->create(['order_id' => $order->id, 'affiliate_id' => $order->affiliate_id, 'code' => 'VC-PARTIAL', 'customer_email' => 'a@example.com', 'amount' => 27500, 'remaining' => 27500, 'status' => 'active', 'reason' => 'partial']);

        $fresh = $order->fresh();
        $this->assertSame(27500, $fresh->compensationAmountSen());
        $this->assertSame(40000, $fresh->effectiveCostPriceSen());
        $this->assertSame('mixed', $fresh->costBasis());
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

    /**
     * ADR-111 addendum (2026-09-22) — `effectiveCostPriceSen()`/
     * `costBasis()` reverse-engineer which cost figure actually produced
     * the stored `platform_profit`, rather than trusting whether
     * `real_cost_price_sen` merely happens to be non-null (the
     * reconciliation feature flag can be off at capture time — decision
     * 2 always captures it — so its presence alone doesn't mean it was
     * USED). A catalog-based `platform_profit` (real-cost-reconciliation
     * flag was off when this order delivered) must read as 'estimated'.
     */
    public function test_cost_basis_is_estimated_when_the_stored_profit_was_computed_from_catalog_cost(): void
    {
        $order = $this->makeOrder([
            'cost_price' => 900, 'selling_price' => 1000, 'affiliate_profit' => 0,
            // platform_profit computed from catalog cost_price (900), NOT real_cost_price_sen.
            'platform_profit' => 100,
            'real_cost_price_sen' => 850,
        ]);

        $this->assertSame('estimated', $order->costBasis());
        $this->assertSame(900, $order->effectiveCostPriceSen());
    }

    /** The mirror case — platform_profit genuinely was derived from the real cost. */
    public function test_cost_basis_is_real_when_the_stored_profit_was_computed_from_real_cost(): void
    {
        $order = $this->makeOrder([
            'cost_price' => 900, 'selling_price' => 1000, 'affiliate_profit' => 0,
            'real_cost_price_sen' => 850,
            // 1000 - 850 - 0 = 150.
            'platform_profit' => 150,
        ]);

        $this->assertSame('real', $order->costBasis());
        $this->assertSame(850, $order->effectiveCostPriceSen());
    }

    /** No real cost ever captured (order not yet delivered, or FX was unavailable) — falls back to the catalog figure. */
    public function test_cost_basis_is_estimated_when_no_real_cost_was_ever_captured(): void
    {
        $order = $this->makeOrder(['cost_price' => 900, 'real_cost_price_sen' => null]);

        $this->assertSame('estimated', $order->costBasis());
        $this->assertSame(900, $order->effectiveCostPriceSen());
    }

    /** Combo, every leg's real cost known and actually used — 'real', not 'mixed'. */
    public function test_cost_basis_is_real_for_a_combo_order_when_every_leg_has_real_cost(): void
    {
        $a = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'REALALL-A']);
        $b = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'REALALL-B']);
        $order = $this->makeOrder(['selling_price' => 1500, 'affiliate_profit' => 0, 'platform_profit' => 300]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $a->supplier_id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $b->supplier_id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => 500,
        ]);

        $this->assertSame('real', $order->fresh()->costBasis());
        $this->assertSame(1200, $order->fresh()->effectiveCostPriceSen());
    }

    /** Combo, one leg's real cost was genuinely unavailable — falls back to that leg's catalog cost, basis 'mixed'. */
    public function test_cost_basis_is_mixed_for_a_combo_order_when_only_one_leg_has_real_cost(): void
    {
        $a = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'MIXED-A']);
        $b = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'MIXED-B']);
        $order = $this->makeOrder(['selling_price' => 1500, 'affiliate_profit' => 0, 'platform_profit' => 300]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $a->supplier_id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $b->supplier_id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => null,
        ]);

        $this->assertSame('mixed', $order->fresh()->costBasis());
        // 700 (real) + 500 (catalog fallback) = 1200.
        $this->assertSame(1200, $order->fresh()->effectiveCostPriceSen());
    }

    /** Combo, real-cost-reconciliation flag was off at delivery — stored profit used the catalog total, basis 'estimated'. */
    public function test_cost_basis_is_estimated_for_a_combo_order_when_catalog_cost_was_used(): void
    {
        $a = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'ESTALL-A']);
        $b = $this->componentPackage(['cost_price' => 500, 'supplier_package_ref' => 'ESTALL-B']);
        $order = $this->makeOrder(['selling_price' => 1500, 'affiliate_profit' => 0, 'platform_profit' => 500]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $a->supplier_id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $b->supplier_id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value,
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);

        // 1500 - (500 + 500 catalog) - 0 = 500 — matches the catalog total, not the real total (1200).
        $this->assertSame('estimated', $order->fresh()->costBasis());
        $this->assertSame(1000, $order->fresh()->effectiveCostPriceSen());
    }
}
