<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-083 decision 8, fills ADR-110 PR-B.
 */
class MonthlyAccountingSummaryServiceTest extends TestCase
{
    use PartialComboOrders;
    use RefreshDatabase;

    private function service(): MonthlyAccountingSummaryService
    {
        return new MonthlyAccountingSummaryService;
    }

    public function test_sales_revenue_and_cogs_only_count_delivered_paid_test_excluded_orders(): void
    {
        Order::factory()->delivered()->create(['paid_at' => '2026-09-15 10:00:00', 'selling_price' => 1000, 'cost_price' => 900]);
        // Paid but never delivered — no revenue/COGS recognized yet.
        Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::NotStarted, 'paid_at' => '2026-09-15 10:00:00', 'selling_price' => 5000, 'cost_price' => 4000]);
        // A sandbox order — excluded regardless of delivery state.
        Order::factory()->delivered()->create(['paid_at' => '2026-09-15 10:00:00', 'selling_price' => 99999, 'cost_price' => 88888, 'is_test' => true]);
        // Outside the period.
        Order::factory()->delivered()->create(['paid_at' => '2026-08-15 10:00:00', 'selling_price' => 2000, 'cost_price' => 1500]);

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame(1000, $summary['sales_revenue_sen']);
        $this->assertSame(900, $summary['cogs_sen']);
    }

    /**
     * 2026-10-03 audit: the month was built in KL time, converted to UTC,
     * and only then had a month added — so it ended one KL day early on
     * every month whose preceding month is shorter (Mar, May, Jul, Oct,
     * Dec): 31 Oct KL belonged to no month at all.
     */
    public function test_period_is_the_whole_kuala_lumpur_month_including_its_last_day(): void
    {
        // 31 Oct 23:00 KL — October.
        Order::factory()->delivered()->create(['paid_at' => '2026-10-31 15:00:00', 'selling_price' => 1000, 'cost_price' => 900]);
        // 1 Nov 00:00 KL exactly — November, and only November.
        Order::factory()->delivered()->create(['paid_at' => '2026-10-31 16:00:00', 'selling_price' => 7000, 'cost_price' => 6000]);
        // 1 Oct 00:00 KL exactly — October.
        Order::factory()->delivered()->create(['paid_at' => '2026-09-30 16:00:00', 'selling_price' => 300, 'cost_price' => 200]);

        $october = $this->service()->forPeriod(2026, 10);
        $november = $this->service()->forPeriod(2026, 11);

        $this->assertSame(1300, $october['sales_revenue_sen']);
        $this->assertSame(1100, $october['cogs_sen']);
        $this->assertSame(7000, $november['sales_revenue_sen']);
    }

    public function test_settlement_batch_ending_on_the_last_day_of_the_month_counts_in_that_month(): void
    {
        PaymentSettlement::query()->create([
            'date_from' => '2026-10-25', 'date_to' => '2026-10-31',
            'matched_gross_sen' => 0, 'matched_fee_sen' => 100, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 80, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'oct.xlsx',
        ]);

        $this->assertSame(20, $this->service()->forPeriod(2026, 10)['payment_processing_gain_loss_sen']);
        $this->assertSame(0, $this->service()->forPeriod(2026, 11)['payment_processing_gain_loss_sen']);
    }

    /** ADR-094 decision 41 — a settled partial delivery counts what it kept and only its delivered legs' cost. */
    public function test_a_settled_partial_delivery_counts_its_kept_revenue_and_delivered_cost(): void
    {
        $this->settledPartialComboOrder();
        $this->partialComboOrder(); // unsettled — like a Failed order, not yet revenue

        $october = $this->service()->forPeriod(2026, 10);

        $this->assertSame(5000 - 2000, $october['sales_revenue_sen']);
        $this->assertSame(2400, $october['cogs_sen']);
    }

    public function test_membership_revenue_sums_fee_records_in_period(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $plan->quota_sen,
            'expires_at' => now()->addDays(30),
        ]);

        $inPeriod = MembershipFeeRecord::query()->create([
            'membership_id' => $membership->id,
            'membership_plan_id' => $plan->id,
            'amount_sen' => 2500,
            'idempotency_key' => 'idem-in-period',
        ]);
        $inPeriod->forceFill(['created_at' => '2026-09-10 00:00:00'])->save();

        $outOfPeriod = MembershipFeeRecord::query()->create([
            'membership_id' => $membership->id,
            'membership_plan_id' => $plan->id,
            'amount_sen' => 2500,
            'idempotency_key' => 'idem-out-of-period',
        ]);
        $outOfPeriod->forceFill(['created_at' => '2026-08-01 00:00:00'])->save();

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame(2500, $summary['membership_revenue_sen']);
    }

    public function test_payment_processing_gain_loss_is_matched_fee_minus_file_fee_across_settlements_in_month(): void
    {
        PaymentSettlement::query()->create([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-07',
            'matched_gross_sen' => 0, 'matched_fee_sen' => 100, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 90, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'a.xlsx',
        ]);
        PaymentSettlement::query()->create([
            'date_from' => '2026-09-08', 'date_to' => '2026-09-14',
            'matched_gross_sen' => 0, 'matched_fee_sen' => 50, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 55, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'b.xlsx',
        ]);
        // Outside the month.
        PaymentSettlement::query()->create([
            'date_from' => '2026-10-01', 'date_to' => '2026-10-07',
            'matched_gross_sen' => 0, 'matched_fee_sen' => 1000, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 1, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'c.xlsx',
        ]);

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame((100 - 90) + (50 - 55), $summary['payment_processing_gain_loss_sen']);
    }

    public function test_supplier_prepaid_topup_sums_transfers_excluding_voided_and_excluding_fee(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 20000, 'fee_myr' => 500,
            'currency' => 'MYR', 'amount_foreign_received' => '19500.0000',
        ]);
        $transfer->forceFill(['created_at' => '2026-09-05 00:00:00'])->save();

        $voided = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 99999, 'fee_myr' => 0,
            'currency' => 'MYR', 'amount_foreign_received' => '99999.0000', 'voided_at' => now(),
        ]);
        $voided->forceFill(['created_at' => '2026-09-06 00:00:00'])->save();

        $summary = $this->service()->forPeriod(2026, 9);

        // 2026-09-30 audit fix: capital-only now (was 20500, capital + fee
        // bundled) — the fee gets its own additive line below, so a
        // founder manually copying both into the external SaaS never
        // double-counts the fee portion.
        $this->assertSame(20000, $summary['supplier_prepaid_topup_sen']);
        $this->assertSame(500, $summary['bank_transfer_fees_sen']);
    }

    public function test_bank_transfer_fees_excludes_voided(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);

        $voided = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 99999, 'fee_myr' => 777,
            'currency' => 'MYR', 'amount_foreign_received' => '99999.0000', 'voided_at' => now(),
        ]);
        $voided->forceFill(['created_at' => '2026-09-06 00:00:00'])->save();

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame(0, $summary['bank_transfer_fees_sen']);
    }

    /**
     * 2026-09-30 addendum — Bucket C decision 9: "money you hold for
     * resellers, which isn't yours." Always the CURRENT total across
     * every reseller's wallet ledger, never period-scoped — a request
     * for a past month's period still returns today's real balance
     * (there's no historical snapshot mechanism).
     */
    public function test_reseller_wallet_balance_is_the_current_total_across_every_reseller(): void
    {
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 1, 'type' => 'wallet_topup', 'amount' => 30000, 'reference_type' => 'wallet_topup_attempt', 'reference_id' => 1]);
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 1, 'type' => 'wallet_debit', 'amount' => -5000, 'reference_type' => 'order', 'reference_id' => 1]);
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 2, 'type' => 'wallet_topup', 'amount' => 10000, 'reference_type' => 'wallet_topup_attempt', 'reference_id' => 2]);
        // A different owner type must never leak into this total.
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::Platform->value, 'owner_id' => null, 'type' => 'order_profit', 'amount' => 999999, 'reference_type' => 'order', 'reference_id' => 1]);

        // Requesting a period from months ago must still return today's real total, not zero/historical.
        $summary = $this->service()->forPeriod(2020, 1);

        $this->assertSame(30000 - 5000 + 10000, $summary['reseller_wallet_balance_sen']);
    }

    public function test_voucher_liability_issued_sums_vouchers_created_in_period(): void
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'code' => 'TESTVCH1', 'customer_email' => 'a@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'test',
        ]);
        $voucher->forceFill(['created_at' => '2026-09-10 00:00:00'])->save();

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame(500, $summary['voucher_liability_issued_sen']);
    }

    /**
     * ADR-083 decision 5 — the weighted-average rate is computed across
     * the supplier's ENTIRE non-voided transfer history (a genuine
     * "true blended cost of funds"), then applied to this month's own
     * drawdown. Two transfers at different rates: 1000 MYR -> 100 units
     * (rate 10) and 2000 MYR -> 100 units (rate 20) blend to a weighted
     * average of 3000 MYR / 200 units = rate 15. Drawing down 50 units
     * this month costs 50 * 15 = 750 MYR at that blended rate.
     */
    public function test_fx_variance_true_up_uses_the_suppliers_weighted_average_rate_across_all_transfers(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);

        SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000, 'fee_myr' => 0,
            'currency' => 'IDR', 'amount_foreign_received' => '100.0000',
        ]);
        SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 200000, 'fee_myr' => 0,
            'currency' => 'IDR', 'amount_foreign_received' => '100.0000',
        ]);

        $order = Order::factory()->delivered()->create(['paid_at' => '2026-09-15 10:00:00', 'cost_price' => 70000, 'supplier_id' => $supplier->id]);
        SupplierLedgerEntry::query()->forceCreate([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => -50,
            'currency' => 'IDR', 'reference_type' => 'order', 'reference_id' => $order->id,
            'created_at' => '2026-09-15 10:00:00',
        ]);

        $summary = $this->service()->forPeriod(2026, 9);

        // Blended rate 15 MYR/unit * 50 units drawn = 750 MYR = 75000 sen.
        $this->assertSame(70000 - 75000, $summary['supplier_prepaid_fx_variance_sen']);
    }

    public function test_affiliate_commission_expense_is_ledger_sourced_not_the_order_column(): void
    {
        $order = Order::factory()->delivered()->create(['paid_at' => '2026-09-15 10:00:00', 'affiliate_profit' => 999999]);

        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::Affiliate->value,
            'owner_id' => $order->affiliate_id,
            'type' => 'order_profit',
            'amount' => 150,
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ]);

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame(150, $summary['affiliate_commission_expense_sen']);
    }
}
