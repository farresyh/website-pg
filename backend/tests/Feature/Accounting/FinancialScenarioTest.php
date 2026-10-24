<?php

namespace Tests\Feature\Accounting;

use App\Models\Affiliate;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\RecognisedRevenue;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Affiliate\AffiliateEarningsService;
use App\Services\Dashboard\DashboardService;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\ResellerWalletService;
use Database\Seeders\FinancialScenario\FinancialScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-127 decision 13: cross-page invariants, proven on data the real
 * system produced (FinancialScenario). Each later ADR-127 PR adds the
 * invariant it makes true.
 */
class FinancialScenarioTest extends TestCase
{
    use RefreshDatabase;

    private FinancialScenario $scenario;

    private Order $crossMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = FinancialScenario::boot('2026-09-01 09:00');
        $this->buildMonth();
    }

    private function buildMonth(): void
    {
        $s = $this->scenario;
        [$small, $mid, $large] = $s->packages;

        $s->at('2026-09-03 10:00')->pay($s->storefrontOrder($small));
        $s->at('2026-09-05 21:30')->pay($s->storefrontOrder($mid, onAffiliateBrand: true));
        $s->at('2026-09-10 12:00')->resellerApiOrder($large);

        $s->supplierAdapter->costNext(900);
        $s->at('2026-09-12 09:00')->pay($s->storefrontOrder($mid));

        $s->supplierAdapter->failNext();
        $failed = $s->at('2026-09-20 15:00')->pay($s->storefrontOrder($small));
        $s->at('2026-09-21 10:00')->compensate($failed);

        // Paid at 23:50 on the last day of September, delivered ten minutes into October.
        $s->supplierAdapter->pendingNext();
        $this->crossMonth = $s->at('2026-09-30 23:50')->pay($s->storefrontOrder($large, onAffiliateBrand: true));
        $s->at('2026-10-01 00:10')->supplierDelivers($this->crossMonth);

        $s->at('2026-10-02 11:00')->pay($s->storefrontOrder($small));
    }

    public function test_the_scenario_produces_every_outcome_it_scripts(): void
    {
        $this->assertSame(6, Order::query()->where('delivery_status', DeliveryStatus::Delivered->value)->count());
        $this->assertSame(1, Order::query()->where('delivery_status', DeliveryStatus::Failed->value)->count());
        $this->assertSame(2, Order::query()->where('affiliate_profit', '>', 0)->count());
        $this->assertSame(1, Order::query()->whereNotNull('wallet_reseller_id')->count());
    }

    /** Invariant 6: selling = cost + platform profit + affiliate profit, with real cost after delivery. */
    public function test_every_delivered_order_conserves_its_selling_price(): void
    {
        Order::query()->where('delivery_status', DeliveryStatus::Delivered->value)->each(function (Order $o) {
            $cost = $o->real_cost_price_sen ?? $o->cost_price;
            $this->assertSame($o->selling_price, $cost + $o->platform_profit + $o->affiliate_profit, "order {$o->reference_number}");
        });
    }

    /** Invariant 10: every Delivered order from an API supplier has exactly one drawdown. */
    public function test_every_delivered_api_order_has_exactly_one_drawdown(): void
    {
        Order::query()->where('delivery_status', DeliveryStatus::Delivered->value)->each(function (Order $o) {
            $this->assertSame(1, SupplierLedgerEntry::query()->where('reference_type', 'order')->where('reference_id', $o->id)->count(), "order {$o->reference_number}");
        });
    }

    /** Invariant 5: a wallet or earnings balance is the same number on every page that shows it. */
    public function test_wallet_and_affiliate_balances_agree_across_pages(): void
    {
        $wallet = app(ResellerWalletService::class)->balance($this->scenario->reseller);
        $position = app(CashPositionService::class)->asOf(2026, 10);

        $this->assertGreaterThan(0, $wallet);
        $this->assertSame($wallet, $this->scenario->apiBalanceSen());
        $this->assertSame($wallet, app(DashboardService::class)->health()['reseller_wallet_liability_sen']);
        $this->assertSame($wallet, app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 10)['reseller_wallet_balance_sen']);
        $this->assertSame($wallet, $position['claims']['reseller_wallets_sen']);

        $earnings = Affiliate::query()->get()->sum(fn (Affiliate $a) => app(AffiliateEarningsService::class)->balance($a));
        $this->assertGreaterThan(0, $earnings);
        $this->assertSame($earnings, $position['claims']['affiliate_earnings_sen']);
    }

    /** ADR-127 decision 1: revenue, cost and affiliate commission belong to the month the order was delivered. */
    public function test_an_order_paid_in_one_month_and_delivered_in_the_next_is_october_revenue(): void
    {
        $summary = app(MonthlyAccountingSummaryService::class);
        $september = $summary->forPeriod(2026, 9);
        $october = $summary->forPeriod(2026, 10);
        $order = $this->crossMonth->fresh();

        $this->assertSame($order->selling_price, $october['sales_revenue_sen'] - $this->smallOctoberOrderSellingSen());
        $this->assertSame($order->affiliate_profit, $october['affiliate_commission_expense_sen']);
        $this->assertNotContains($order->id, app(RecognisedRevenue::class)->recognisedOrders(...MonthlyAccountingSummaryService::monthBounds(2026, 9))->pluck('id'));
        $this->assertSame(0, $september['affiliate_commission_expense_sen'] - $this->septemberAffiliateCommissionSen());
    }

    /**
     * Invariant 4, as conservation: at any month end, every paid and
     * uncompensated order is either recognised revenue or still owed to
     * the customer, never both and never neither.
     */
    public function test_paid_money_is_either_recognised_or_owed_at_every_month_end(): void
    {
        foreach ([[2026, 9], [2026, 10]] as [$year, $month]) {
            [, $toExclusive] = MonthlyAccountingSummaryService::monthBounds($year, $month);
            $paid = Order::query()
                ->where('is_test', false)
                ->where('paid_at', '<', $toExclusive)
                ->get()
                ->reject(fn (Order $o) => $o->isAlreadyCompensated())
                ->sum('selling_price');

            $recognised = app(RecognisedRevenue::class)->revenueSen(null, $toExclusive);
            $owed = app(CashPositionService::class)->asOf($year, $month)['claims']['orders_undelivered_sen'];

            $this->assertSame($paid, $recognised + $owed, "{$year}-{$month}");
        }
    }

    private function smallOctoberOrderSellingSen(): int
    {
        return (int) Order::query()->where('paid_at', '>=', MonthlyAccountingSummaryService::monthBounds(2026, 10)[0])->where('id', '!=', $this->crossMonth->id)->sum('selling_price');
    }

    private function septemberAffiliateCommissionSen(): int
    {
        [$from, $to] = MonthlyAccountingSummaryService::monthBounds(2026, 9);

        return (int) Order::query()->where('delivered_at', '>=', $from)->where('delivered_at', '<', $to)->sum('affiliate_profit');
    }
}
