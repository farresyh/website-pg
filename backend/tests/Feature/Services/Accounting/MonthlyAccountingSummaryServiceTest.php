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
use Tests\TestCase;

/**
 * ADR-083 decision 8, fills ADR-110 PR-B.
 */
class MonthlyAccountingSummaryServiceTest extends TestCase
{
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

    public function test_payment_processing_gain_loss_is_expected_fee_minus_file_fee_across_settlements_in_month(): void
    {
        PaymentSettlement::query()->create([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-07',
            'expected_gross_sen' => 0, 'expected_fee_sen' => 100, 'expected_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 90, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'a.xlsx',
        ]);
        PaymentSettlement::query()->create([
            'date_from' => '2026-09-08', 'date_to' => '2026-09-14',
            'expected_gross_sen' => 0, 'expected_fee_sen' => 50, 'expected_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 55, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'b.xlsx',
        ]);
        // Outside the month.
        PaymentSettlement::query()->create([
            'date_from' => '2026-10-01', 'date_to' => '2026-10-07',
            'expected_gross_sen' => 0, 'expected_fee_sen' => 1000, 'expected_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 1, 'file_net_sen' => 0,
            'status' => 'pending', 'original_filename' => 'c.xlsx',
        ]);

        $summary = $this->service()->forPeriod(2026, 9);

        $this->assertSame((100 - 90) + (50 - 55), $summary['payment_processing_gain_loss_sen']);
    }

    public function test_supplier_prepaid_topup_sums_transfers_excluding_voided(): void
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

        $this->assertSame(20500, $summary['supplier_prepaid_topup_sen']);
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
