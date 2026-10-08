<?php

namespace Tests\Feature\Services\Report;

use App\Models\Affiliate;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-104 2026-10-08 addendum R8–R11 — Failed & compensated and
 * outstanding store credit. The compensation aggregates are set-based SQL
 * mirroring Order::cashCompensationSen() / compensationAmountSen(); the
 * invariant test holds them to the model methods (the item-63 lesson).
 */
class ReportCompensationTest extends TestCase
{
    use PartialComboOrders;
    use RefreshDatabase;

    private const PAID_AT = '2026-10-15 04:00:00';

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge(['paid_at' => self::PAID_AT], $overrides));
    }

    private function voucher(?Order $source, array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'order_id' => $source?->id,
            'affiliate_id' => $source?->affiliate_id ?? $this->primaryAffiliate()->id,
            'code' => 'VC-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ], $overrides));
    }

    private function otherAffiliate(): Affiliate
    {
        return Affiliate::query()->create(['business_name' => 'Ohahastore', 'markup_pct' => 5, 'status' => 'active']);
    }

    private function october(): array
    {
        return (new ReportService)->dateRangeFromDates('2026-10-01', '2026-10-31');
    }

    /** @return list<Order> */
    private function seedEveryCompensationForm(): array
    {
        // Failed retail, cash paid back as a store-credit voucher.
        $voucherIssued = $this->order(['delivery_status' => DeliveryStatus::Failed]);
        $this->voucher($voucherIssued, ['amount' => 1100, 'remaining' => 1100]);

        // Failed reseller-wallet order, refunded to the wallet.
        $reseller = Reseller::query()->create(['business_name' => 'Comp Reseller', 'is_active' => true]);
        $walletRefunded = $this->order(['delivery_status' => DeliveryStatus::Failed, 'wallet_reseller_id' => $reseller->id, 'final_amount' => 1000, 'transaction_fee' => 0]);
        (new LedgerService)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 1000, 'wallet_refund', 'order', $walletRefunded->id);

        // Failed full-voucher-cover order: the voucher it paid with is restored, nothing new issued.
        $paidWith = $this->voucher(null, ['remaining' => 0, 'status' => 'exhausted']);
        $restoredOnly = $this->order(['delivery_status' => DeliveryStatus::Failed, 'voucher_discount' => 1000, 'final_amount' => 0, 'transaction_fee' => 0]);
        VoucherRedemption::query()->create(['voucher_id' => $paidWith->id, 'order_id' => $restoredOnly->id, 'amount' => 1000, 'restored_amount' => 1000, 'status' => 'restored']);

        // Failed part-voucher, part-cash: both halves given back.
        $mixed = $this->order(['delivery_status' => DeliveryStatus::Failed, 'voucher_discount' => 400, 'final_amount' => 700]);
        $partPaidWith = $this->voucher(null, ['amount' => 400, 'remaining' => 0, 'status' => 'exhausted']);
        VoucherRedemption::query()->create(['voucher_id' => $partPaidWith->id, 'order_id' => $mixed->id, 'amount' => 400, 'restored_amount' => 400, 'status' => 'restored']);
        $this->voucher($mixed, ['amount' => 700, 'remaining' => 700]);

        // Settled combo partial (voucher 2000), and a delivered order with nothing.
        $partial = $this->settledPartialComboOrder();
        $delivered = $this->order(['delivery_status' => DeliveryStatus::Delivered]);

        return [$voucherIssued, $walletRefunded, $restoredOnly, $mixed, $partial, $delivered];
    }

    public function test_compensation_aggregates_equal_the_order_model_methods(): void
    {
        $orders = collect($this->seedEveryCompensationForm())->map->fresh();

        $section = (new ReportService)->failedAndCompensated(...[...$this->october(), null]);

        $this->assertSame((int) $orders->sum->cashCompensationSen(), $section['voucher_issued'] + $section['wallet_refund']);
        $this->assertSame((int) $orders->sum->compensationAmountSen(), $section['voucher_issued'] + $section['wallet_refund'] + $section['voucher_restored']);
        $this->assertSame(1100 + 700 + 2000, $section['voucher_issued']);
        $this->assertSame(1000, $section['wallet_refund']);
        $this->assertSame(1000 + 400, $section['voucher_restored']);
    }

    public function test_failed_count_and_paid_amount(): void
    {
        $this->seedEveryCompensationForm();

        $section = (new ReportService)->failedAndCompensated(...[...$this->october(), null]);

        $this->assertSame(4, $section['failed_count']);
        $this->assertSame(1100 + 1000 + 0 + 700, $section['failed_paid_amount']);
    }

    /** R8 — a liability, not an expense: profit is untouched by compensation. */
    public function test_compensation_is_not_netted_from_profit(): void
    {
        $delivered = $this->order(['delivery_status' => DeliveryStatus::Delivered]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $delivered->id);
        $failed = $this->order(['delivery_status' => DeliveryStatus::Failed]);
        $this->voucher($failed, ['amount' => 1100]);

        $summary = (new ReportService)->summary(...[...$this->october(), null]);

        $this->assertSame(100, $summary['platform_profit']);
    }

    /** R9 — scoped by the order's paid_at, not the voucher's created_at. */
    public function test_failure_figures_follow_the_order_paid_at(): void
    {
        $september = $this->order(['delivery_status' => DeliveryStatus::Failed, 'paid_at' => '2026-09-30 15:00:00']); // 30 Sep 23:00 KL
        $this->voucher($september, ['amount' => 1100]); // created now, in any month

        $section = (new ReportService)->failedAndCompensated(...[...$this->october(), null]);

        $this->assertSame(0, $section['failed_count']);
        $this->assertSame(0, $section['voucher_issued']);
    }

    public function test_affiliate_filter_scopes_the_section(): void
    {
        $this->seedEveryCompensationForm();
        $other = $this->otherAffiliate();
        $theirs = $this->order(['delivery_status' => DeliveryStatus::Failed, 'affiliate_id' => $other->id]);
        $this->voucher($theirs, ['amount' => 1100]);

        $section = (new ReportService)->failedAndCompensated(...[...$this->october(), $other->id]);

        $this->assertSame(1, $section['failed_count']);
        $this->assertSame(1100, $section['voucher_issued']);
        $this->assertSame(0, $section['wallet_refund']);
    }

    /** R10 — Σ remaining of active, unexpired compensation vouchers, as of now. */
    public function test_outstanding_store_credit_counts_only_live_compensation_vouchers(): void
    {
        $failed = fn () => $this->order(['delivery_status' => DeliveryStatus::Failed]);
        $this->voucher($failed(), ['remaining' => 600]);                                   // counted
        $this->voucher($failed(), ['remaining' => 250, 'expires_at' => now()->addDay()]);  // counted
        $this->voucher($failed(), ['remaining' => 900, 'expires_at' => now()->subDay()]);  // expired
        $this->voucher($failed(), ['remaining' => 0, 'status' => 'exhausted']);
        $this->voucher($failed(), ['remaining' => 300, 'status' => 'revoked']);
        $this->voucher(null, ['remaining' => 5000]);                                       // Path A
        $this->voucher($this->order(['is_test' => true]), ['remaining' => 700]);            // sandbox source

        $this->assertSame(850, (new ReportService)->outstandingStoreCredit(null));
    }

    public function test_outstanding_store_credit_follows_the_affiliate_filter(): void
    {
        $other = $this->otherAffiliate();
        $this->voucher($this->order(['delivery_status' => DeliveryStatus::Failed]), ['remaining' => 600]);
        $this->voucher($this->order(['delivery_status' => DeliveryStatus::Failed, 'affiliate_id' => $other->id]), ['remaining' => 250]);

        $this->assertSame(250, (new ReportService)->outstandingStoreCredit($other->id));
    }
}
