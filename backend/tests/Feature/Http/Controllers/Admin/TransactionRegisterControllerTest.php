<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Models\WalletTopupAttempt;
use App\Models\Withdrawal;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Withdrawal\WithdrawalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-083 decision 9: the Transaction Register — one row per
 * money-moving event across four otherwise-separate sources.
 */
class TransactionRegisterControllerTest extends TestCase
{
    use PartialComboOrders;
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/transactions')->assertForbidden();
    }

    public function test_index_includes_a_paid_order_row(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => now(),
        ]);
        // Mirrors OrderFulfillmentService::creditProfit() — the real
        // source of truth for a delivered order's realized profit.
        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::Platform->value, 'owner_id' => null,
            'type' => 'order_profit', 'amount' => 100, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', 'KRS-REG-1');
        $this->assertSame('order', $row['type']);
        $this->assertSame(1000, $row['gross_sen']);
        $this->assertSame(100, $row['fee_sen']);
        $this->assertSame(900, $row['cost_sen']);
        $this->assertSame(100, $row['net_sen']);
        $this->assertSame('Digiflazz', $row['supplier']);
        $this->assertSame('chip', $row['funding_source']);
    }

    /**
     * 2026-09-30 audit fix: a reseller wallet-paid order's `gross_sen` is
     * spend from an already-collected wallet balance, not a fresh bank
     * inflow — `funding_source` flags it so the Gross column can be
     * filtered/grouped instead of double-counted against the top-up that
     * originally funded the wallet.
     */
    public function test_index_flags_a_reseller_wallet_paid_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $reseller = Reseller::query()->create(['business_name' => 'Naeem Industries']);
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'wallet_reseller_id' => $reseller->id,
            'order_number' => 'KRS-REG-WALLET',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => now(),
        ]);

        $row = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'))
            ->firstWhere('reference', 'KRS-REG-WALLET');

        $this->assertSame('reseller_wallet', $row['funding_source']);
    }

    /**
     * 2026-09-30 audit fix: a failed order already refunded to a
     * reseller's wallet used to look exactly like a normal completed
     * sale here, with no trace of the money going back. The original
     * order row keeps its real figures (never rewritten in place — same
     * discipline as a voided supplier transfer); the refund gets its own
     * row, mirroring supplierRefundRows()'s shape.
     */
    public function test_index_includes_a_wallet_refund_row_for_a_failed_wallet_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $reseller = Reseller::query()->create(['business_name' => 'Naeem Industries']);
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'wallet_reseller_id' => $reseller->id,
            'order_number' => 'KRS-REG-FAILED-WALLET',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'paid_at' => now(),
        ]);
        $refundEntry = LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => $reseller->id,
            'type' => 'wallet_refund', 'amount' => 1000, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'));

        $orderRow = $rows->firstWhere('reference', 'KRS-REG-FAILED-WALLET');
        $this->assertSame(1000, $orderRow['gross_sen'], 'the original sale attempt stays visible with its real figures');
        $this->assertNull($orderRow['cost_sen'], 'never delivered — no real supplier cost was drawn down');

        $refundRow = $rows->firstWhere('reference', "Ledger entry #{$refundEntry->id}");
        $this->assertSame('reseller_wallet_refund', $refundRow['type']);
        $this->assertSame(1000, $refundRow['net_sen']);
        $this->assertStringContainsString('KRS-REG-FAILED-WALLET', $refundRow['description']);
    }

    /**
     * Regression: `Order.platform_profit` is stamped at checkout time,
     * before the delivery outcome is known — a paid-but-undelivered
     * order must show net_sen=0 here (no ledger_entries row exists yet
     * for it), never the stale checkout-time column value, mirroring
     * ReportService::profitTotals()/DashboardService's own rule.
     */
    /** ADR-094 decision 41 — a partial delivery drew down its delivered legs' cost. */
    public function test_index_shows_the_delivered_legs_cost_for_a_settled_partial_delivery(): void
    {
        $order = $this->settledPartialComboOrder();
        $this->actAsSuperAdmin();

        $row = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'))
            ->firstWhere('reference', $order->order_number);

        $this->assertSame(2400, $row['cost_sen']);
        $this->assertSame(600, $row['net_sen']);
    }

    public function test_index_shows_zero_net_for_a_paid_but_undelivered_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-UNDELIVERED',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100, // stamped at checkout time — not yet realized
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'paid_at' => now(),
        ]);
        // Deliberately no LedgerEntry row — creditProfit() only runs on delivery.

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', 'KRS-REG-UNDELIVERED');
        $this->assertSame(0, $row['net_sen'], 'net_sen must come from ledger_entries, never the checkout-time Order.platform_profit column');
        $this->assertNull($row['cost_sen'], 'cost_sen must be null until delivered — recordOrderDrawdown() only ever runs on a successful delivery, so an undelivered order never drew down real supplier cost');
        $this->assertSame(1000, $row['gross_sen'], 'gross_sen still shows — the customer really did pay, regardless of delivery outcome');
    }

    public function test_index_excludes_a_test_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-TEST',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => now(),
            'is_test' => true,
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $this->assertNull(collect($response->json('data'))->firstWhere('reference', 'KRS-REG-TEST'));
    }

    public function test_index_includes_a_supplier_transfer_row(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'fee_myr' => 250,
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
            'reference_no' => 'WISE-REG-1',
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', 'WISE-REG-1');
        $this->assertSame('supplier_transfer', $row['type']);
        $this->assertSame(250, $row['fee_sen']);
        $this->assertSame(-100250, $row['net_sen']);
        $this->assertSame('3700000.0000', $row['amount_foreign']);
    }

    public function test_index_includes_a_supplier_refund_row_but_not_a_topup_or_drawdown(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::Topup->value, 'amount' => '5000000.0000', 'currency' => 'IDR',
        ]);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => '-15000.0000', 'currency' => 'IDR',
        ]);
        $refund = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::Refund->value, 'amount' => '15000.0000', 'currency' => 'IDR', 'reason' => 'test refund',
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'));

        $refundRow = $rows->firstWhere('reference', "Ledger entry #{$refund->id}");
        $this->assertSame('supplier_refund', $refundRow['type']);
        $this->assertSame('15000.0000', $refundRow['amount_foreign']);
        $this->assertSame(1, $rows->where('type', 'supplier_refund')->count());
        $this->assertSame(0, $rows->whereIn('type', ['supplier_topup', 'supplier_drawdown'])->count());
    }

    public function test_index_includes_a_path_b_voucher_but_not_a_standalone_one(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-2',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 0,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $affiliateId = $this->primaryAffiliate()->id;
        Voucher::query()->create([
            'order_id' => $order->id, 'affiliate_id' => $affiliateId, 'code' => 'VC-REGPATHB', 'customer_email' => 'buyer@example.com',
            'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'delivery failed',
        ]);
        Voucher::query()->create([
            'affiliate_id' => $affiliateId, 'code' => 'VC-REGSTANDALONE', 'customer_email' => 'other@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'goodwill',
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'));

        $pathB = $rows->firstWhere('reference', 'VC-REGPATHB');
        $this->assertSame('voucher_issued', $pathB['type']);
        $this->assertSame(-1000, $pathB['net_sen']);
        $this->assertNull($rows->firstWhere('reference', 'VC-REGSTANDALONE'));
    }

    public function test_export_streams_a_csv(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-CSV-1',
        ]);

        $response = $this->get('/api/accounting/transactions/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('WISE-CSV-1', $content);
        $this->assertStringContainsString('Date,Type,Reference', $content);
        $this->assertStringContainsString('Status', $content);
    }

    /**
     * 2026-09-30 audit addendum (Bucket C decision 4): a voided
     * transfer's row keeps its real original Net figure, but its
     * VOID_REVERSAL correction is FX-only (never an MYR net_sen) — so a
     * naive sum of the whole Net column won't reconcile to a real bank
     * statement. This footer row gives the one number that actually
     * does: the real, non-voided transfer stays in, the voided one is
     * excluded.
     */
    public function test_export_footer_totals_net_excluding_voided_rows(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 10000, 'fee_myr' => 500,
            'currency' => 'IDR', 'amount_foreign_received' => '370000.0000', 'reference_no' => 'WISE-KEEP',
        ]);
        $voided = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 99999, 'fee_myr' => 0,
            'currency' => 'IDR', 'amount_foreign_received' => '999990.0000', 'reference_no' => 'WISE-VOIDED',
        ]);
        $voided->update(['voided_at' => now(), 'void_reason' => 'test']);

        $content = $this->get('/api/accounting/transactions/export')->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $content)));
        $footer = collect($rows)->first(fn ($r) => ($r[3] ?? null) === 'TOTAL Net (excluding voided rows)');

        $this->assertNotNull($footer, 'export must end with a computed reconciling total');
        // Only the non-voided transfer's net_sen counts: -(10000 + 500) = -10500 sen = -105.00.
        $this->assertSame('-105.00', $footer[9]);
    }

    // ── ADR-083 2026-09-28 addendum: pagination ──────────────────────────

    public function test_index_is_paginated(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        for ($i = 1; $i <= 3; $i++) {
            SupplierTransfer::query()->create([
                'transferred_on' => '2026-09-05',
                'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 10000 * $i,
                'currency' => 'IDR', 'amount_foreign_received' => '100000.0000', 'reference_no' => "WISE-PAGE-{$i}",
            ]);
        }

        $response = $this->getJson('/api/accounting/transactions?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('total'));
        $this->assertSame(2, $response->json('last_page'));

        $page2 = $this->getJson('/api/accounting/transactions?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }

    /**
     * Item 63 (2026-10-04): from/to are KL days. An order paid at 07:30 KL
     * (23:30 UTC the day before) belongs to the KL day, not the UTC one.
     */
    public function test_the_date_filter_uses_kl_days(): void
    {
        $this->actAsSuperAdmin();
        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-KL',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => CarbonImmutable::parse('2026-10-03 23:30:00', 'UTC'),
        ]);

        $refs = fn (string $day) => collect($this->getJson("/api/accounting/transactions?from={$day}&to={$day}")->assertOk()->json('data'))->pluck('reference');

        $this->assertTrue($refs('2026-10-04')->contains($order->order_number));
        $this->assertFalse($refs('2026-10-03')->contains($order->order_number));
    }

    public function test_index_filters_by_type(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-TYPEFILTER',
        ]);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::Refund->value, 'amount' => '15000.0000', 'currency' => 'IDR', 'reason' => 'x',
        ]);

        $response = $this->getJson('/api/accounting/transactions?type=supplier_transfer')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->every(fn ($row) => $row['type'] === 'supplier_transfer'));
        $this->assertNotNull($rows->firstWhere('reference', 'WISE-TYPEFILTER'));
    }

    // ── ADR-083 2026-09-28 addendum: void/adjustment visibility fix ─────

    /** A voided transfer's own row keeps its original figures (never zeroed) — real double-entry practice never rewrites a historical entry in place. */
    public function test_index_shows_a_voided_transfer_with_its_original_figures_tagged_voided(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-VOIDVIS',
        ]);
        $transfer->update(['voided_at' => now(), 'void_reason' => 'never arrived']);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', 'WISE-VOIDVIS');
        $this->assertSame('voided', $row['status']);
        // Original net_sen — NOT zeroed/rewritten.
        $this->assertSame(-100000, $row['net_sen']);
    }

    /** ADR-083 2026-10-10 addendum, decision 15: a transfer row is dated, and range-filtered, by the day the money left the bank. */
    public function test_supplier_transfer_row_is_dated_by_transferred_on(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-30',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-LATE',
        ]);
        $transfer->forceFill(['created_at' => '2026-10-02 02:00:00'])->save();

        $september = collect($this->getJson('/api/accounting/transactions?from=2026-09-01&to=2026-09-30')->assertOk()->json('data'));
        $row = $september->firstWhere('reference', 'WISE-LATE');
        $this->assertNotNull($row);
        // Midnight KL on 30 Sep, as a UTC instant like every other row's date.
        $this->assertSame('2026-09-29T16:00:00+00:00', $row['date']);

        $october = collect($this->getJson('/api/accounting/transactions?from=2026-10-01&to=2026-10-31')->assertOk()->json('data'));
        $this->assertNull($october->firstWhere('reference', 'WISE-LATE'));
    }

    /** A MANUAL_ADJUSTMENT/VOID_REVERSAL entry against a transfer gets its own row, dated at its own created_at — not the original transfer's date, and not invisible (the bug this addendum fixes). */
    public function test_index_includes_a_supplier_adjustment_row_dated_at_its_own_created_at(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-ADJVIS',
        ]);
        // created_at isn't mass-assignable on either model (not in $fillable) — forceFill/forceCreate to backdate it.
        $transfer->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
        $entry = SupplierLedgerEntry::forceCreate([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::ManualAdjustment->value, 'amount' => '-15000.0000',
            'currency' => 'IDR', 'reference_type' => 'supplier_transfer', 'reference_id' => $transfer->id, 'reason' => 'missed fee',
            'created_at' => '2026-06-15 00:00:00',
        ]);

        // Filtering to a range that excludes the original transfer's date
        // but includes the adjustment's own date must still surface it —
        // this is the whole point of dating it at its own created_at.
        $response = $this->getJson('/api/accounting/transactions?from=2026-06-01&to=2026-06-30')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertNull($rows->firstWhere('reference', 'WISE-ADJVIS'));
        $adjustmentRow = $rows->firstWhere('reference', "Adjustment #{$entry->id} (Transfer #{$transfer->id})");
        $this->assertNotNull($adjustmentRow);
        $this->assertSame('supplier_adjustment', $adjustmentRow['type']);
        $this->assertSame('-15000.0000', $adjustmentRow['amount_foreign']);
        $this->assertNull($adjustmentRow['net_sen']);
        $this->assertStringContainsString('Manual adjustment', $adjustmentRow['description']);
    }

    public function test_index_labels_a_void_reversal_differently_from_a_partial_adjustment(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000',
        ]);
        $entry = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::VoidReversal->value, 'amount' => '-3700000.0000',
            'currency' => 'IDR', 'reference_type' => 'supplier_transfer', 'reference_id' => $transfer->id, 'reason' => 'wrong account',
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', "Adjustment #{$entry->id} (Transfer #{$transfer->id})");
        $this->assertStringContainsString('Void reversal', $row['description']);
    }

    // ── 2026-09-28 register-completeness addendum: membership/wallet-topup/withdrawal rows ──

    public function test_index_includes_a_membership_fee_row(): void
    {
        $this->actAsSuperAdmin();
        $plan = MembershipPlan::query()->first() ?? MembershipPlan::query()->create([
            'name' => 'Tier 1', 'fee_sen' => 890, 'quota_sen' => 10000, 'discount_percent' => 50,
        ]);
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com', 'membership_plan_id' => $plan->id, 'status' => 'active',
            'cycle_started_at' => now(), 'quota_remaining_sen' => $plan->quota_sen, 'expires_at' => now()->addMonth(),
        ]);
        $record = MembershipFeeRecord::query()->create([
            'membership_id' => $membership->id, 'membership_plan_id' => $plan->id, 'amount_sen' => 890,
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', "Membership Fee #{$record->id}");
        $this->assertSame('membership_payment', $row['type']);
        $this->assertSame(890, $row['gross_sen']);
        $this->assertSame(890, $row['net_sen']);
        $this->assertStringContainsString('member@example.com', $row['description']);
        $this->assertNull($row['fee_sen'], 'no matching MembershipCheckoutAttempt — falls back to the fee-less shape rather than guessing');
    }

    /**
     * 2026-09-30 audit fix: when the fee record's `idempotency_key`
     * matches a real `MembershipCheckoutAttempt.subscription_number`
     * (the join `MembershipSubscriptionService::completePaidAttempt()`
     * establishes), the real CHIP fee is now shown — the same
     * total_charged_sen - fee_sen computation
     * `SettlementReconciliationService::resolveMatch()` already uses.
     */
    public function test_index_shows_the_real_chip_fee_for_a_membership_row_with_a_matching_checkout_attempt(): void
    {
        $this->actAsSuperAdmin();
        $plan = MembershipPlan::query()->first() ?? MembershipPlan::query()->create([
            'name' => 'Tier 1', 'fee_sen' => 1990, 'quota_sen' => 10000, 'discount_percent' => 70,
        ]);
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member2@example.com', 'membership_plan_id' => $plan->id, 'status' => 'active',
            'cycle_started_at' => now(), 'quota_remaining_sen' => $plan->quota_sen, 'expires_at' => now()->addMonth(),
        ]);
        MembershipCheckoutAttempt::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member2@example.com', 'membership_plan_id' => $plan->id,
            'fee_sen' => 1990, 'total_charged_sen' => 2090, 'channel_code' => 'fpx',
            'subscription_number' => 'SUB-REG-1', 'idempotency_key' => 'idem-1',
            'status' => MembershipCheckoutAttemptStatus::Paid->value,
        ]);
        $record = MembershipFeeRecord::query()->create([
            'membership_id' => $membership->id, 'membership_plan_id' => $plan->id, 'amount_sen' => 1990,
            'idempotency_key' => 'SUB-REG-1',
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('reference', "Membership Fee #{$record->id}");
        $this->assertSame(2090, $row['gross_sen'], 'gross now reflects the real total charged, not just the plan fee');
        $this->assertSame(100, $row['fee_sen'], 'the real CHIP fee — total_charged_sen minus the (confusingly-named) plan fee_sen');
        $this->assertSame(1990, $row['net_sen'], 'net stays the plan revenue actually recognized');
    }

    public function test_index_includes_a_paid_wallet_topup_row_but_not_a_pending_one(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Naeem Industries']);
        WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'TOPUP-PAID-1', 'amount_sen' => 30000,
            'total_charged_sen' => 30500, 'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Paid->value,
            'expires_at' => now()->addMinutes(30),
        ]);
        WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'TOPUP-PENDING-1', 'amount_sen' => 10000,
            'total_charged_sen' => 10200, 'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'));

        $paidRow = $rows->firstWhere('reference', 'TOPUP-PAID-1');
        $this->assertSame('reseller_wallet_topup', $paidRow['type']);
        $this->assertSame(30000, $paidRow['net_sen']);
        $this->assertSame(500, $paidRow['fee_sen']);
        $this->assertStringContainsString('Naeem Industries', $paidRow['description']);
        $this->assertNull($rows->firstWhere('reference', 'TOPUP-PENDING-1'));
    }

    public function test_index_includes_a_completed_withdrawal_row_but_not_a_pending_one(): void
    {
        $this->actAsSuperAdmin();
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);
        $completed = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 5000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Completed, 'processed_at' => now(),
        ]);
        Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 2000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Pending,
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data'));

        $row = $rows->firstWhere('reference', "Withdrawal #{$completed->id}");
        $this->assertSame('withdrawal_payout', $row['type']);
        $this->assertSame(-5000, $row['net_sen']);
        $this->assertStringContainsString('Acme', $row['description']);
        $this->assertSame(1, $rows->where('type', 'withdrawal_payout')->count());
    }
}
