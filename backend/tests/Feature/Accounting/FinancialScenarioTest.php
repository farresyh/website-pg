<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriodClose;
use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use App\Models\Voucher;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\RecognisedRevenue;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Affiliate\AffiliateEarningsService;
use App\Services\Dashboard\DashboardService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Report\ReportService;
use App\Services\Reseller\ResellerWalletService;
use Carbon\CarbonImmutable;
use Database\Seeders\FinancialScenario\FinancialScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private AccountingPeriodClose $septemberClose;

    /** @var list<array<string, mixed>> */
    private array $septemberRegisterAtClose;

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

        // The supplier's answer is unknown; the admin confirms it on the dashboard, charged RM1 over catalog.
        $s->supplierAdapter->ambiguousNext();
        $ambiguous = $s->at('2026-09-14 13:00')->pay($s->storefrontOrder($mid));
        $s->at('2026-09-15 09:30')->adminConfirmsDelivered($ambiguous, $mid->cost_price + 100);

        $s->supplierAdapter->failNext();
        $failed = $s->at('2026-09-20 15:00')->pay($s->storefrontOrder($small));
        $s->at('2026-09-21 10:00')->compensate($failed);

        // Paid at 23:50 on the last day of September, delivered ten minutes into October.
        $s->supplierAdapter->pendingNext();
        $this->crossMonth = $s->at('2026-09-30 23:50')->pay($s->storefrontOrder($large, onAffiliateBrand: true));
        $s->at('2026-10-01 00:10')->supplierDelivers($this->crossMonth);

        $s->at('2026-10-02 11:00')->pay($s->storefrontOrder($small));

        // Paid in September, still with the supplier when September is closed.
        $s->supplierAdapter->pendingNext();
        $lateSeptember = $s->at('2026-09-29 20:00')->pay($s->storefrontOrder($mid));

        $this->septemberClose = $s->at('2026-10-10 10:00')->closeMonth(2026, 9);
        $this->septemberRegisterAtClose = app(TransactionRegisterService::class)->rows(...MonthlyAccountingSummaryService::monthBounds(2026, 9));

        // After the close: the September order is delivered, the wallet moves, a failure is compensated.
        $s->at('2026-10-12 09:00')->supplierDelivers($lateSeptember);
        $s->at('2026-10-15 14:00')->resellerApiOrder($mid);
        $s->supplierAdapter->failNext();
        $octoberFailed = $s->at('2026-10-16 16:00')->pay($s->storefrontOrder($small));
        $s->at('2026-10-17 10:00')->compensate($octoberFailed);
    }

    public function test_the_scenario_produces_every_outcome_it_scripts(): void
    {
        $this->assertSame(9, Order::query()->where('delivery_status', DeliveryStatus::Delivered->value)->count());
        $this->assertSame(1, Order::query()->whereNotNull('real_cost_price_sen')->whereJsonContains('supplier_response->manually_confirmed', true)->count());
        $this->assertSame(2, Order::query()->where('delivery_status', DeliveryStatus::Failed->value)->count());
        $this->assertSame(2, Order::query()->where('affiliate_profit', '>', 0)->count());
        $this->assertSame(2, Order::query()->whereNotNull('wallet_reseller_id')->count());
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
        $revenue = app(RecognisedRevenue::class);
        $september = $revenue->recognisedOrders(...MonthlyAccountingSummaryService::monthBounds(2026, 9));
        $october = $revenue->recognisedOrders(...MonthlyAccountingSummaryService::monthBounds(2026, 10));

        $this->assertNotContains($this->crossMonth->id, $september->pluck('id'));
        $this->assertContains($this->crossMonth->id, $october->pluck('id'));
        $this->assertSame((int) $october->sum('selling_price'), app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 10)['sales_revenue_sen']);
        $this->assertSame((int) $october->sum('affiliate_profit'), app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 10)['affiliate_commission_expense_sen']);
        $this->assertSame((int) $september->sum('affiliate_profit'), app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9)['affiliate_commission_expense_sen']);
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

    /** Invariant 2 (ADR-127 decision 3): what happens after a close never changes the closed month. */
    public function test_a_closed_months_figures_never_move_after_the_close(): void
    {
        $live = app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9);
        // The close's own allocation was pending when it was taken; it is a posting now.
        $position = app(CashPositionService::class)->asOf(2026, 9);

        $this->assertSame($this->septemberClose->profit_lines, $live);
        foreach (['assets', 'claims'] as $side) {
            $this->assertSame($this->septemberClose->equation[$side], $position[$side], $side);
        }

        $this->assertSame($this->septemberRegisterAtClose, app(TransactionRegisterService::class)->rows(...MonthlyAccountingSummaryService::monthBounds(2026, 9)));
    }

    /** ADR-127 P1: an order recognised after its paid month gets its own Register row in the month it was recognised. */
    public function test_the_register_books_a_late_recognition_in_its_own_month(): void
    {
        $october = collect(app(TransactionRegisterService::class)->rows(...MonthlyAccountingSummaryService::monthBounds(2026, 10)));
        $recognised = $october->where('type', 'order_recognised')->pluck('reference');

        $this->assertContains($this->crossMonth->order_number, $recognised);
        $this->assertCount(2, $recognised);
    }

    /** ADR-127 P1 on Reports: profit belongs to the month its order was recognised, so a past month's Reports profit cannot move later. */
    public function test_reports_profit_is_filed_by_recognition(): void
    {
        $reports = app(ReportService::class);

        foreach ([[2026, 9], [2026, 10]] as [$year, $month]) {
            [$from, $to] = MonthlyAccountingSummaryService::monthBounds($year, $month);
            $recognised = app(RecognisedRevenue::class)->recognisedOrders($from, $to);
            $summary = $reports->summary(CarbonImmutable::instance($from), CarbonImmutable::instance($to), null);

            $this->assertSame((int) $recognised->sum('affiliate_profit'), $summary['affiliate_profit'], "{$year}-{$month}");
            $this->assertSame(
                (int) LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->whereIn('reference_id', $recognised->pluck('id'))->sum('amount'),
                $summary['platform_profit'],
                "{$year}-{$month}",
            );
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function month(int $year, int $month): array
    {
        return array_map(fn ($at) => CarbonImmutable::instance($at), MonthlyAccountingSummaryService::monthBounds($year, $month));
    }

    /** Invariant 3: the Reports Bridge walks paid sales to the Summary's revenue with nothing unexplained. */
    public function test_the_bridge_explains_every_month(): void
    {
        foreach ([[2026, 9], [2026, 10]] as [$year, $month]) {
            $bridge = app(ReportService::class)->accountingBridge(...self::month($year, $month));

            $this->assertSame(0, $bridge['unexplained_difference'], "{$year}-{$month}");
            $this->assertSame(app(MonthlyAccountingSummaryService::class)->forPeriod($year, $month)['sales_revenue_sen'], $bridge['recognised_revenue']);
        }
    }

    /** Invariant 7: per order, the Register's net (its order row, recognition row and corrections) is the ledger's platform profit. */
    public function test_the_register_nets_to_the_ledger_per_order(): void
    {
        $net = collect(app(TransactionRegisterService::class)->rows(null, null))
            ->whereIn('type', ['order', 'order_recognised', 'order_profit_correction'])
            ->groupBy('reference')
            ->map(fn ($rows) => (int) $rows->sum('net_sen'));

        Order::query()->where('payment_status', 'paid')->each(function (Order $o) use ($net) {
            $ledger = (int) LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->where('reference_id', $o->id)->sum('amount');
            $this->assertSame($ledger, $net->get($o->order_number, 0), $o->order_number);
        });
    }

    /** Invariant 8: Reports and the Report Assistant's view give the same sales (by paid date) and profit (by recognition). */
    public function test_reports_and_the_report_assistant_agree(): void
    {
        foreach ([['2026-09-01', '2026-10-01', 2026, 9], ['2026-10-01', '2026-11-01', 2026, 10]] as [$from, $to, $year, $month]) {
            $summary = app(ReportService::class)->summary(...[...self::month($year, $month), null]);
            $view = fn (string $column, string $date) => (int) DB::table('llm_report_orders')->where($date, '>=', $from)->where($date, '<', $to)->sum($column);

            $this->assertSame($summary['total_sales'], $view('net_sales', 'paid_at_kl'), "{$year}-{$month} sales");
            $this->assertSame($summary['platform_profit'], $view('platform_profit', 'recognised_at_kl'), "{$year}-{$month} profit");
        }
    }

    /** Invariant 9: store credit on Reports is Cash Position's claim. */
    public function test_store_credit_agrees_between_reports_and_cash_position(): void
    {
        $this->assertGreaterThan(0, app(ReportService::class)->outstandingStoreCredit(null));
        $this->assertSame(
            app(CashPositionService::class)->asOf(2026, 10)['claims']['vouchers_outstanding_sen'],
            app(ReportService::class)->outstandingStoreCredit(null),
        );
    }

    /** Invariant 11: a sandbox order changes no figure on any money page. */
    public function test_a_sandbox_order_changes_no_figure(): void
    {
        $figures = fn () => [
            app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9),
            app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 10),
            app(CashPositionService::class)->asOf(2026, 10),
            app(ReportService::class)->summary(...[...self::month(2026, 10), null]),
            app(ReportService::class)->outstandingStoreCredit(null),
            app(TransactionRegisterService::class)->rows(null, null),
        ];
        $before = $figures();

        $delivered = Order::factory()->delivered()->create(['is_test' => true, 'paid_at' => now(), 'selling_price' => 99900, 'final_amount' => 99900]);
        app(LedgerService::class)->creditOrderProfit($delivered);
        $failed = Order::factory()->create(['is_test' => true, 'paid_at' => now(), 'delivery_status' => DeliveryStatus::Failed, 'final_amount' => 50000]);
        Voucher::query()->create(['order_id' => $failed->id, 'affiliate_id' => $failed->affiliate_id, 'code' => 'SANDBOX-1', 'customer_email' => 'x@sandbox.test', 'amount' => 50000, 'remaining' => 50000, 'status' => 'active', 'reason' => 'sandbox']);

        $this->assertEquals($before, $figures());
    }

    /** ADR-127 P1: a manual profit correction is profit of the month it was made, never of its order's month. */
    public function test_a_later_profit_correction_never_moves_a_past_months_reports_profit(): void
    {
        $reports = app(ReportService::class);
        $september = fn () => $reports->summary(...[...self::month(2026, 9), null])['platform_profit'];
        $october = fn () => $reports->summary(...[...self::month(2026, 10), null])['platform_profit'];
        [$septemberBefore, $octoberBefore] = [$september(), $october()];
        $septemberOrder = app(RecognisedRevenue::class)->recognisedOrders(...MonthlyAccountingSummaryService::monthBounds(2026, 9))->first();

        $this->scenario->at('2026-10-20 10:00');
        app(LedgerService::class)->credit(LedgerOwnerType::Platform, null, -50, 'order_profit', 'order', $septemberOrder->id, $this->scenario->admin->id, 'Scenario: supplier charged more');

        $this->assertSame($septemberBefore, $september());
        $this->assertSame($octoberBefore - 50, $october());
        $this->assertSame(0, app(ReportService::class)->profitBridge(...self::month(2026, 10))['unexplained_difference']);
    }

    /** ADR-127 decision 9: owner profit (market rate) walks to operating profit (accounts) with nothing unexplained. */
    public function test_the_profit_bridge_explains_every_month(): void
    {
        foreach ([[2026, 9], [2026, 10]] as [$year, $month]) {
            $bridge = app(ReportService::class)->profitBridge(...self::month($year, $month));

            $this->assertSame(0, $bridge['unexplained_difference'], "{$year}-{$month}: ".json_encode($bridge));
            $this->assertSame(app(MonthlyAccountingSummaryService::class)->forPeriod($year, $month)['operating_profit_sen'], $bridge['operating_profit']);
        }
    }
}
