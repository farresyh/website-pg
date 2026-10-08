<?php

namespace Tests\Feature\Services\ReportAssistant;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-087 2026-10-08 addendum: the Report Assistant's view must give the
 * Reports page's numbers. The view inlines the Net Sales SQL (a frozen
 * migration), so this is the guard that fails if either side drifts.
 */
class LlmReportViewParityTest extends TestCase
{
    use RefreshDatabase;

    private function profit(Order $order, LedgerOwnerType $owner, int $amount): void
    {
        LedgerEntry::query()->create([
            'owner_type' => $owner->value, 'owner_id' => $owner === LedgerOwnerType::Platform ? null : $order->affiliate_id,
            'type' => 'order_profit', 'amount' => $amount, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
    }

    public function test_view_totals_match_the_reports_summary(): void
    {
        $paid = ['paid_at' => '2026-09-15 10:00:00'];

        $delivered = Order::factory()->delivered()->create($paid);
        $this->profit($delivered, LedgerOwnerType::Platform, 100);

        // Affiliate order: platform and affiliate each earn a share.
        $affiliate = Order::factory()->delivered()->create($paid + ['final_amount' => 1300, 'pricing_basis' => 'affiliate']);
        $this->profit($affiliate, LedgerOwnerType::Platform, 80);
        $this->profit($affiliate, LedgerOwnerType::Affiliate, 120);

        // Voucher-discounted order: final_amount is already net of it.
        $voucher = Order::factory()->delivered()->create($paid + ['voucher_discount' => 400, 'final_amount' => 700]);
        $this->profit($voucher, LedgerOwnerType::Platform, 100);

        // Retail failure: paid, no profit, stays in sales (voucher, ADR-004).
        Order::factory()->create($paid + ['delivery_status' => DeliveryStatus::Failed]);

        // Wallet failure refunded: nets out of sales.
        $reseller = Reseller::query()->create(['business_name' => 'Naeem', 'is_active' => true]);
        $refunded = Order::factory()->create($paid + ['delivery_status' => DeliveryStatus::Failed, 'final_amount' => 34351, 'wallet_reseller_id' => $reseller->id, 'pricing_basis' => 'reseller-wallet']);
        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => $reseller->id, 'type' => 'wallet_refund',
            'amount' => 34351, 'reference_type' => 'order', 'reference_id' => $refunded->id,
        ]);

        // Excluded on both sides.
        Order::factory()->delivered()->create($paid + ['is_test' => true]);
        Order::factory()->pending()->create();

        $report = app(ReportService::class)->summary(null, null, null);
        $view = DB::table('llm_report_orders')
            ->selectRaw('COUNT(*) as orders_count, SUM(net_sales) as net_sales, SUM(wallet_refund) as wallet_refund, SUM(platform_profit) as platform_profit, SUM(affiliate_profit) as affiliate_profit')
            ->first();

        $this->assertSame($report['orders_count'], (int) $view->orders_count);
        $this->assertSame($report['total_sales'], (int) $view->net_sales);
        $this->assertSame($report['platform_profit'], (int) $view->platform_profit);
        $this->assertSame($report['affiliate_profit'], (int) $view->affiliate_profit);
        $this->assertSame(34351, (int) $view->wallet_refund);
        $this->assertSame(1100 + 1300 + 700 + 1100, $report['total_sales']); // sanity: the refunded order nets to 0
    }
}
