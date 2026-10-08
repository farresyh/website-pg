<?php

namespace Tests\Feature\Services\Report;

use App\Models\Order;
use App\Models\Reseller;
use App\Models\Voucher;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-104 2026-10-08 addendum R7 — Paid sales walks to the Monthly
 * Summary's recognised revenue with nothing left over. One fixture holds
 * every order shape that sits between the two figures.
 */
class ReportAccountingBridgeTest extends TestCase
{
    use PartialComboOrders;
    use RefreshDatabase;

    private const PAID_AT = '2026-10-10 04:00:00';

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge(['paid_at' => self::PAID_AT, 'voucher_discount' => 0], $overrides));
    }

    private function october(): array
    {
        return (new ReportService)->dateRangeFromDates('2026-10-01', '2026-10-31');
    }

    private function seedEveryShape(): void
    {
        // Delivered, plain: selling 1000 + fee 100.
        $this->order(['delivery_status' => DeliveryStatus::Delivered]);
        // Delivered with a checkout voucher discount of 300.
        $this->order(['delivery_status' => DeliveryStatus::Delivered, 'voucher_discount' => 300, 'final_amount' => 800]);
        // Delivered, full voucher cover: nothing charged.
        $this->order(['delivery_status' => DeliveryStatus::Delivered, 'selling_price' => 700, 'voucher_discount' => 700, 'transaction_fee' => 0, 'final_amount' => 0, 'payment_method' => 'voucher']);
        // Failed retail, compensated with a store-credit voucher.
        $failed = $this->order(['delivery_status' => DeliveryStatus::Failed]);
        Voucher::query()->create(['order_id' => $failed->id, 'affiliate_id' => $failed->affiliate_id, 'code' => 'VC-BRIDGE1', 'customer_email' => 'x@example.com', 'amount' => 1100, 'remaining' => 1100, 'status' => 'active', 'reason' => 'failed']);
        // Failed reseller-wallet order, refunded to the wallet.
        $reseller = Reseller::query()->create(['business_name' => 'Bridge Reseller', 'is_active' => true]);
        $walletOrder = $this->order(['delivery_status' => DeliveryStatus::Failed, 'wallet_reseller_id' => $reseller->id, 'transaction_fee' => 0, 'final_amount' => 1000, 'payment_method' => 'wallet']);
        (new LedgerService)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 1000, 'wallet_refund', 'order', $walletOrder->id);
        // In flight.
        $this->order(['delivery_status' => DeliveryStatus::Processing, 'selling_price' => 500, 'final_amount' => 600]);
        // Combo partials: one settled (keeps 3000), one not yet.
        $this->settledPartialComboOrder();
        $this->partialComboOrder();
        // Never paid, and a sandbox order: in neither figure.
        $this->order(['payment_status' => PaymentStatus::Pending, 'paid_at' => null]);
        $this->order(['delivery_status' => DeliveryStatus::Delivered, 'is_test' => true, 'selling_price' => 99999]);
    }

    public function test_the_bridge_walks_paid_sales_to_recognised_revenue_with_no_unexplained_difference(): void
    {
        $this->seedEveryShape();

        $bridge = (new ReportService)->accountingBridge(...$this->october());

        // 1100 + 800 + 0 + 1100 + (1000 − 1000 refund) + 600 + 5100 + 5100
        $this->assertSame(13800, $bridge['paid_sales']);
        $this->assertSame(100 + 100 + 0 + 100 + 0 + 100 + 100 + 100, $bridge['transaction_fees']);
        $this->assertSame(300 + 700, $bridge['voucher_discounts']);
        // failed retail 1000, refunded wallet 1000 − 1000, in flight 500, unsettled partial 5000
        $this->assertSame(6500, $bridge['not_recognised']);
        $this->assertSame(2000, $bridge['partial_compensation']);
        // delivered 1000 + 1000 + 700, settled partial 5000 − 2000
        $this->assertSame(5700, $bridge['recognised_revenue']);
        $this->assertSame(0, $bridge['unexplained_difference']);
    }

    public function test_recognised_revenue_is_the_monthly_summary_sales_revenue(): void
    {
        $this->seedEveryShape();

        $bridge = (new ReportService)->accountingBridge(...$this->october());

        $this->assertSame(
            (new MonthlyAccountingSummaryService)->forPeriod(2026, 10)['sales_revenue_sen'],
            $bridge['recognised_revenue'],
        );
    }

    /** The stress-test case: Accounting does not filter payment_status, so a delivered-but-unpaid order must show, not vanish. */
    public function test_a_delivered_order_that_is_not_paid_surfaces_as_an_unexplained_difference(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::Delivered]);
        $this->order(['delivery_status' => DeliveryStatus::Delivered, 'payment_status' => PaymentStatus::Pending]);

        $bridge = (new ReportService)->accountingBridge(...$this->october());

        $this->assertSame(-1000, $bridge['unexplained_difference']);
    }

    public function test_all_time_has_a_bridge_too(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::Delivered]);

        $bridge = (new ReportService)->accountingBridge(null, null);

        $this->assertSame(1000, $bridge['recognised_revenue']);
        $this->assertSame(0, $bridge['unexplained_difference']);
    }
}
