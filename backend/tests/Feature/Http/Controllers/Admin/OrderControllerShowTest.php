<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** This file: the order detail response. */
class OrderControllerShowTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    public function test_show_returns_full_order_detail(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $order = $this->order([
            'game_id' => $game->id, 'package_id' => $package->id, 'supplier_id' => $supplier->id,
            'supplier_response' => ['supplier_ref' => 'GV-123'],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('order_number', $order->order_number);
        $response->assertJsonPath('game.name', 'Free Fire Global');
        $response->assertJsonPath('package.name', '100 Diamonds');
        $response->assertJsonPath('supplier.name', 'Gamevion');
        $response->assertJsonPath('supplier_response.supplier_ref', 'GV-123');
        // ADR-111 addendum — no real cost ever captured on this fixture,
        // so the catalog snapshot is what the stored platform_profit
        // was actually computed from.
        $response->assertJsonPath('effective_cost_price', 900);
        $response->assertJsonPath('cost_basis', 'estimated');
    }

    /**
     * ADR-111 addendum (2026-09-22) — the "real" case: platform_profit
     * genuinely was derived from real_cost_price_sen (not merely
     * present), so Order Detail must show the real figure, not the
     * stale catalog cost_price.
     */
    public function test_show_reports_the_real_cost_basis_when_the_stored_profit_was_derived_from_it(): void
    {
        $order = $this->order([
            'cost_price' => 900, 'selling_price' => 1000, 'affiliate_profit' => 0,
            'real_cost_price_sen' => 850,
            // 1000 - 850 - 0 = 150, not the catalog-derived 100.
            'platform_profit' => 150,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('effective_cost_price', 850);
        $response->assertJsonPath('cost_basis', 'real');
    }

    /**
     * ADR-094 decision 12/9 (Phase 4): the admin detail screen's leg
     * breakdown + Issue Voucher gating, for a genuine partial-delivery
     * combo order.
     */
    public function test_show_includes_delivery_legs_and_partial_delivery_computed_fields(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $delivered = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $failed = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500,
        ]);
        $order = $this->order(['package_id' => $combo->id, 'delivery_status' => DeliveryStatus::PartiallyDelivered->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
            'selling_price_sen' => $delivered->standard_selling_price,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $failed->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::Failed->value, 'failure_reason' => 'Insufficient balance',
            'selling_price_sen' => $failed->standard_selling_price,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('compensable', true);
        // ADR-094 decision 33: order()'s defaults give cash 1000
        // (final_amount 1100 - transaction_fee 100); the undelivered share
        // by frozen selling_price_sen weight (27500 of 71500) ->
        // 1000 * 27500/71500 ≈ 384.6, rounds to 385.
        $response->assertJsonPath('compensation_preview', ['instrument' => 'voucher', 'cash_sen' => 385, 'voucher_restore_sen' => 0, 'total_sen' => 385]);
        $response->assertJsonCount(2, 'delivery_legs');
        $response->assertJsonPath('delivery_legs.0.leg_number', 1);
        $response->assertJsonPath('delivery_legs.0.status', 'delivered');
        $response->assertJsonPath('delivery_legs.0.component_package.name', '4810 Diamonds');
        // 2026-09-16 addendum: the leg's own "supplier_reference" is the
        // supplier's transaction/response ref, not the product SKU —
        // admin needs the component's supplier_package_ref too, to know
        // exactly which SKU this leg submitted.
        $response->assertJsonPath('delivery_legs.0.component_package.supplier_package_ref', 'GV-4810');
        $response->assertJsonPath('delivery_legs.1.status', 'failed');
        $response->assertJsonPath('delivery_legs.1.failure_reason', 'Insufficient balance');
        // ADR-107 decision 3, generalized by ADR-111 decision 7 — an
        // unsettled partial-delivery order never has this flag set
        // (only OrderFulfillmentService's own Delivered-branch
        // reconciliation writes it, see Order::hasReconciledProfitFlag()).
        $response->assertJsonPath('profit_reconciled_flagged', false);
    }

    /** ADR-094 decision 28: a leg still ambiguous keeps the order NeedsReview — nothing to compensate yet. */
    public function test_show_offers_no_compensation_while_a_leg_is_still_ambiguous(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-voucher-amount-needs-review-test', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-voucher-amount-needs-review-test']);
        $delivered = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $needsReview = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500,
        ]);
        $order = $this->order(['package_id' => $combo->id, 'delivery_status' => DeliveryStatus::NeedsReview->value]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
            'selling_price_sen' => $delivered->standard_selling_price,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $needsReview->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value, 'failure_reason' => 'Duplicate reference',
            'selling_price_sen' => $needsReview->standard_selling_price,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('compensable', false);
        $response->assertJsonPath('compensation_preview', null);
    }

    /**
     * ADR-107 decision 3, generalized by ADR-111 decision 7 — the Order
     * Detail visibility signal: a plain passthrough of the persisted
     * `profit_reconciled_flagged` column (written by
     * OrderFulfillmentService's own reconciliation, exercised separately
     * in OrderFulfillmentServiceComboTest/OrderFulfillmentServiceTest —
     * this only proves the controller surfaces it correctly).
     */
    public function test_show_flags_a_delivered_order_with_a_reconciled_profit_flag(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 4810, 'cost_price' => 40000, 'standard_selling_price' => 44000,
        ]);
        $order = $this->order([
            'package_id' => $combo->id, 'delivery_status' => DeliveryStatus::Delivered->value,
            'platform_profit' => -500, 'profit_reconciled_flagged' => true,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
            'selling_price_sen' => $component->standard_selling_price,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('profit_reconciled_flagged', true);
    }

    /**
     * ADR-108 2026-10-04 addendum — Order Detail shows earned profit from the
     * ledger beside the expected columns: nothing for a failed order, the
     * corrected figure for a delivered one.
     */
    public function test_show_exposes_earned_profit_from_the_ledger(): void
    {
        $this->actingAsAdmin();
        $failed = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'platform_profit' => 1001]);
        $delivered = $this->order(['order_number' => 'KRS-EARNED-2', 'delivery_status' => DeliveryStatus::Delivered->value, 'platform_profit' => 37]);
        app(LedgerService::class)->creditOrderProfit($delivered);
        app(LedgerService::class)->credit(LedgerOwnerType::Platform, null, -10, 'order_profit', 'order', $delivered->id, reason: 'correction');

        $this->getJson("/api/orders/{$failed->id}")->assertOk()
            ->assertJsonPath('earned_profit', null)
            ->assertJsonPath('platform_profit', 1001);
        $this->getJson("/api/orders/{$delivered->id}")->assertOk()
            ->assertJsonPath('earned_profit.platform', 27);
    }

    /** An ordinary Failed order is compensated in full — u = 1. */
    public function test_show_previews_full_compensation_for_an_ordinary_failed_order(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('compensable', true);
        $response->assertJsonPath('compensation_preview.total_sen', 1000);
        $response->assertJsonCount(0, 'delivery_legs');
    }

    /** ADR-026 addendum (2026-09-16), renamed by ADR-102 decision 3/5 — drives the admin panel's Resend Delivery futility warning. */
    public function test_show_delivery_retry_unsafe_with_same_reference_true_for_a_digiflazz_terminal_rc(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->order([
            'supplier_id' => $supplier->id,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('delivery_retry_unsafe_with_same_reference', true);
        // ADR-102 decision 3 — NeedsReview + non-combo is exactly the
        // scoped-unsafe case, so the button-gating flag is true too.
        $response->assertJsonPath('resend_unsafe_to_override', true);
    }

    /**
     * ADR-102 decision 3 — the scoped rule's actual point: a Failed
     * non-combo order is NEVER scoped-unsafe (decision 9 always
     * regenerates its reference on resend), even when the raw signal
     * is true.
     */
    public function test_show_resend_unsafe_to_override_false_for_a_failed_non_combo_order_with_the_raw_signal_true(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->order([
            'supplier_id' => $supplier->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('delivery_retry_unsafe_with_same_reference', true);
        $response->assertJsonPath('resend_unsafe_to_override', false);
    }

    public function test_show_returns_404_for_a_nonexistent_order(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/999999');

        $response->assertNotFound();
    }

    /**
     * Decision #4: "Delivery Logs" — show() surfaces every resend
     * attempt, most recent first.
     */
    public function test_show_includes_resend_attempt_history(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'package_id' => $package->id,
            'cost_price_sen' => 950,
            'standard_selling_price_sen' => 950,
            'price_diff_sen' => 50,
            'outcome' => 'success',
            'triggered_by' => 'Admin User',
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('resend_attempts'));
        $this->assertSame(50, $response->json('resend_attempts.0.price_diff_sen'));
        $this->assertSame('Admin User', $response->json('resend_attempts.0.triggered_by'));
    }

    /**
     * The Admin Panel's "Issue Voucher" button (Orders page) needs to
     * know whether one already exists for this order, without a
     * separate lookup — show() eager-loads the voucher relation.
     */
    public function test_show_includes_the_issued_voucher_when_one_exists(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'payment_status' => PaymentStatus::Paid->value]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'VC-TESTCODE',
            'customer_email' => $order->customer_email,
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'Delivery failed - refund voucher',
            'created_by' => AdminUser::factory()->create()->id,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher.code', 'VC-TESTCODE');
    }

    public function test_show_voucher_is_null_when_none_issued(): void
    {
        $this->actingAsAdmin();
        $order = $this->order();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher', null);
    }

    public function test_show_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true]);
        $this->actingAsAdmin();

        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }

    /** ADR-102 decision 11 (b) — the "Wallet Refund" card needs the underlying LedgerEntry's amount/created_at, not just the wallet_refunded boolean. */
    public function test_show_exposes_the_wallet_refund_ledger_entrys_amount_and_created_at(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()->assertJsonPath('wallet_refund.amount', 945);
        $this->assertNotNull($response->json('wallet_refund.created_at'));
    }

    /** An order with no wallet refund at all exposes null, not a missing key or a zeroed-out object. */
    public function test_show_exposes_a_null_wallet_refund_when_none_exists(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()->assertJsonPath('wallet_refund', null);
    }

    /**
     * ADR-102 decision 11 (a) — the voucher this order was PAID WITH
     * (orders.voucher_id) is a real, distinct fact from the
     * compensation `voucher` relation (issued because delivery
     * failed) — never exposed anywhere before this.
     */
    public function test_show_exposes_the_voucher_this_order_was_paid_with(): void
    {
        $this->actingAsAdmin();
        $paidWith = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-PAID-WITH',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 300,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->order(['voucher_id' => $paidWith->id]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('paid_with_voucher.code', 'KRS-PAID-WITH')
            ->assertJsonPath('paid_with_voucher.remaining', 300)
            ->assertJsonPath('paid_with_voucher.display_status', 'active');
    }

    /** §16 item 84: the Refund cards show an expired voucher as expired, not active. */
    public function test_show_marks_a_voucher_past_its_expiry_as_expired(): void
    {
        $this->actingAsAdmin();
        $paidWith = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-LAPSED',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 300,
            'status' => 'active',
            'expires_at' => now()->subDay(),
            'reason' => 'test',
        ]);
        $order = $this->order(['voucher_id' => $paidWith->id]);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('paid_with_voucher.status', 'active')
            ->assertJsonPath('paid_with_voucher.display_status', 'expired');
    }

    /** ADR-102 decision 12 — show() mirrors the same three badge booleans index() exposes, so OrderDetail's shape (extending the list's) is never inconsistent between the two endpoints. */
    public function test_show_exposes_the_same_compensation_badges_as_index(): void
    {
        $this->actingAsAdmin();
        $paidWith = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-USED-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->order(['voucher_id' => $paidWith->id]);
        VoucherRedemption::query()->create(['voucher_id' => $paidWith->id, 'order_id' => $order->id, 'amount' => 500, 'status' => 'reserved']);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('has_used_voucher', true)
            ->assertJsonPath('has_compensation_voucher', false)
            ->assertJsonPath('has_wallet_refund', false);
    }
}
