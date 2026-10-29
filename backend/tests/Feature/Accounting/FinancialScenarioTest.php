<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriodClose;
use App\Models\Affiliate;
use App\Models\BudgetEnvelopePosting;
use App\Models\LedgerEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Models\Withdrawal;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\RecognisedRevenue;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Affiliate\AffiliateEarningsService;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerFilters;
use App\Services\Dashboard\DashboardService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Report\ReportService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Withdrawal\WithdrawalStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\FinancialScenario\FinancialScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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

    /** @var array<string, Order> */
    private array $joki = [];

    /** @var list<Supplier> */
    private array $penjoki;

    private Order $memberOrder;

    private Order $resent;

    /** @var list<Order> */
    private array $idrOrders = [];

    private Withdrawal $withdrawal;

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
        [$ffSmall, $ffMid] = $s->idrPackages;

        // The money around the orders: capital in, both suppliers funded
        // (the IDR one at a deposit rate better than the market's),
        // membership on, and CHIP paying out every week.
        $s->at('2026-09-01 09:10')->fundCompany(2_000_000);
        $s->at('2026-09-01 09:20')->supplierTransfer($s->apiSupplier, 300_000);
        $s->at('2026-09-01 09:30')->fundIdrSupplier(100_000, 3_750.0);
        $s->at('2026-09-01 09:40')->enableMembership();

        $s->at('2026-09-02 12:00')->subscribeMember(7001);
        $this->memberOrder = $s->at('2026-09-09 19:00')->pay($s->storefrontOrder($mid, customer: 7001, asMember: true));

        $this->idrOrders[] = $s->at('2026-09-04 15:00')->pay($s->storefrontOrder($ffSmall));
        $s->at('2026-09-15 08:00')->setIdrRate(0.000275);
        $this->idrOrders[] = $s->at('2026-09-18 16:00')->pay($s->storefrontOrder($ffMid));
        $s->at('2026-09-20 09:00')->fundIdrSupplier(50_000, 3_650.0);
        $s->at('2026-09-11 11:00')->walletTopupViaChip(50_000);

        // Failed, then resent on the same package: delivered, never compensated.
        $s->supplierAdapter->failNext();
        $this->resent = $s->at('2026-09-25 13:00')->pay($s->storefrontOrder($small));
        $s->at('2026-09-26 10:00')->resend($this->resent);

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

        // Joki (ADR-126) at an 80% payout share: delivered, failed (the
        // penjoki gets nothing), reassigned (only the second is paid), and
        // one paid in September and finished in October.
        $this->penjoki = $s->at('2026-09-01 10:00')->enableJoki(80.0);
        [$amir, $budi] = $this->penjoki;
        $this->joki['delivered'] = $s->at('2026-09-06 20:00')->pay($s->jokiOrder(['Epic', 5, 0], ['Epic', 3, 0]));
        $s->at('2026-09-06 20:30')->assignJoki($this->joki['delivered'], $amir);
        $s->at('2026-09-08 22:00')->penjokiDelivers($this->joki['delivered']);

        $this->joki['failed'] = $s->at('2026-09-16 19:00')->pay($s->jokiOrder(['Epic', 5, 0], ['Epic', 4, 0]));
        $s->at('2026-09-16 19:10')->assignJoki($this->joki['failed'], $amir);
        $s->at('2026-09-17 23:00')->penjokiFails($this->joki['failed']);
        $s->at('2026-09-18 10:00')->compensate($this->joki['failed']);

        $this->joki['reassigned'] = $s->at('2026-09-22 18:00')->pay($s->jokiOrder(['Epic', 4, 0], ['Epic', 2, 0], express: true));
        $s->at('2026-09-22 18:05')->assignJoki($this->joki['reassigned'], $amir);
        $s->at('2026-09-23 09:00')->assignJoki($this->joki['reassigned'], $budi);
        $s->at('2026-09-24 21:00')->penjokiDelivers($this->joki['reassigned']);

        $this->joki['cross_month'] = $s->at('2026-09-29 21:00')->pay($s->jokiOrder(['Epic', 5, 0], ['Epic', 4, 0]));
        $s->at('2026-09-29 21:05')->assignJoki($this->joki['cross_month'], $budi);
        $s->at('2026-10-03 22:00')->penjokiDelivers($this->joki['cross_month']);

        // Paid in September, still with the supplier when September is closed.
        $s->supplierAdapter->pendingNext();
        $lateSeptember = $s->at('2026-09-29 20:00')->pay($s->storefrontOrder($mid));

        foreach (['2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06'] as $day) {
            $s->at("{$day} 12:00")->settleChip();
        }
        $this->septemberClose = $s->at('2026-10-10 10:00')->closeMonth(2026, 9);
        $this->septemberRegisterAtClose = app(TransactionRegisterService::class)->rows(...MonthlyAccountingSummaryService::monthBounds(2026, 9));

        // After the close: the September order is delivered, the wallet moves, a failure is compensated.
        $s->at('2026-10-12 09:00')->supplierDelivers($lateSeptember);
        $s->at('2026-10-15 14:00')->resellerApiOrder($mid);
        $s->supplierAdapter->failNext();
        $octoberFailed = $s->at('2026-10-16 16:00')->pay($s->storefrontOrder($small));
        $s->at('2026-10-17 10:00')->compensate($octoberFailed);
        $this->idrOrders[] = $s->at('2026-10-18 20:00')->pay($s->storefrontOrder($ffSmall));
        $this->withdrawal = $s->at('2026-10-20 10:00')->affiliateWithdraws(100);
        $s->at('2026-10-20 12:00')->settleChip();
    }

    public function test_the_scenario_produces_every_outcome_it_scripts(): void
    {
        $this->assertSame(17, Order::query()->where('delivery_status', DeliveryStatus::Delivered->value)->count());
        $this->assertSame(3, Order::query()->where('delivery_status', DeliveryStatus::Failed->value)->count());
        $this->assertSame(1, Order::query()->whereNotNull('real_cost_price_sen')->whereJsonContains('supplier_response->manually_confirmed', true)->count());
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

    /**
     * ADR-126/127 2026-10-24 addenda: the agreed payout is the whole joki
     * cost. A penjoki is owed only for what they delivered; a failed or
     * moved-away order owes them nothing, and nothing goes through the
     * Budget Envelope.
     */
    public function test_a_penjoki_is_owed_only_the_payout_of_what_they_delivered(): void
    {
        [$amir, $budi] = $this->penjoki;
        $delivered = $this->joki['delivered']->fresh();
        $reassigned = $this->joki['reassigned']->fresh();
        $crossMonth = $this->joki['cross_month']->fresh();

        foreach ([$delivered, $reassigned, $crossMonth] as $o) {
            $this->assertSame(DeliveryStatus::Delivered, $o->delivery_status);
            $this->assertSame($o->cost_price, $o->real_cost_price_sen, "order {$o->reference_number}");
            $this->assertSame(intdiv($o->selling_price * 80, 100), $o->cost_price, "order {$o->reference_number}");
        }

        $this->assertEqualsWithDelta(-$delivered->cost_price / 100, (float) $amir->fresh()->supplierLedgerBalance(), 0.001);
        $this->assertEqualsWithDelta(-($reassigned->cost_price + $crossMonth->cost_price) / 100, (float) $budi->fresh()->supplierLedgerBalance(), 0.001);

        $failed = $this->joki['failed']->fresh();
        $this->assertSame(0, SupplierLedgerEntry::query()->where('reference_type', 'order')->where('reference_id', $failed->id)->count());
        $this->assertSame($failed->selling_price, Voucher::query()->where('order_id', $failed->id)->sole()->amount);

        $this->assertSame(0, BudgetEnvelopePosting::query()->whereIn('type', [EnvelopePostingType::Expense->value, EnvelopePostingType::DirectorPaidExpense->value])->count());
    }

    /** ADR-127 decision 6: a joki paid in September and finished in October is October revenue. */
    public function test_a_joki_finished_next_month_is_that_months_revenue(): void
    {
        $order = $this->joki['cross_month']->fresh();
        $this->assertSame('2026-10', $order->recognised_at->setTimezone('Asia/Kuala_Lumpur')->format('Y-m'));
    }

    /**
     * The cash equation closes to the sen. With the bank modelled, the
     * September close has no gap: every ringgit in the bank, at a supplier
     * or still with CHIP is owed to someone or allocated to an envelope.
     * CHIP's RM1-a-payment estimate on what is unsettled at month end
     * offsets the channel fee charged to the customer, whose difference
     * from CHIP's real fee is recognised when the payment settles.
     */
    public function test_the_cash_equation_closes_with_no_gap(): void
    {
        $this->assertGreaterThan(0, $this->septemberClose->equation['chip_unsettled_count']);
        $this->assertSame(0, $this->septemberClose->gap_sen, json_encode($this->septemberClose->equation));
    }

    /**
     * ADR-083 2026-10-25 addendum (§16 item 132): from October an IDR
     * supplier is costed at a moving weighted average, so deposits at
     * different rates in different months, and a balance correction, still
     * leave every close with no gap: each ringgit sent is a cost in some
     * month or still in the prepaid balance.
     */
    public function test_idr_deposits_at_changing_rates_leave_no_gap_at_any_close(): void
    {
        $s = $this->scenario;
        [$ffSmall, $ffMid] = $s->idrPackages;

        $s->at('2026-10-21 09:00')->fundIdrSupplier(80_000, 3_500.0);
        $s->at('2026-10-22 15:00')->pay($s->storefrontOrder($ffMid));
        $s->at('2026-10-27 12:00')->settleChip();
        $october = $s->at('2026-11-05 10:00')->closeMonth(2026, 10);

        $s->at('2026-11-06 09:00')->fundIdrSupplier(60_000, 3_900.0);
        $s->at('2026-11-10 15:00')->pay($s->storefrontOrder($ffSmall));
        $s->at('2026-11-12 15:00')->pay($s->storefrontOrder($ffMid));
        app(SupplierFundingService::class)->recordManualAdjustment(
            SupplierTransfer::query()->latest('id')->firstOrFail(), '-12345.0000', 'Scenario: supplier balance short', $s->admin->id,
        );
        $s->at('2026-11-24 12:00')->settleChip();
        $november = $s->at('2026-12-03 10:00')->closeMonth(2026, 11);

        $this->assertSame(0, $october->gap_sen, json_encode($october->equation));
        $this->assertSame(0, $november->gap_sen, json_encode($november->equation));
        $this->assertLessThan(0, $november->profit_lines['supplier_manual_adjustments_sen']);
    }

    /**
     * ADR-086 2026-10-25 addendum: one order, one place. A reseller-wallet
     * order carries the primary brand's affiliate_id but counts toward its
     * Reseller only, on every view that splits by Affiliate: Reports'
     * breakdown and filter, Customer Analytics' filter, the Affiliates
     * list and the Report Assistant's view. So the brands plus the
     * resellers add up to the whole, never more.
     */
    public function test_a_wallet_order_counts_toward_its_reseller_only(): void
    {
        $s = $this->scenario;
        $reports = app(ReportService::class);
        [$from, $to] = self::month(2026, 9);
        $wallet = Order::query()->whereNotNull('wallet_reseller_id')->where('payment_status', 'paid')->where('is_test', false);
        $this->assertGreaterThan(0, (clone $wallet)->count());
        $this->assertSame(0, (clone $wallet)->where('affiliate_id', '!=', $s->platform->id)->count(), 'the premise: a wallet order carries the primary brand');

        $total = $reports->summary($from, $to, null)['total_sales'];
        $brands = array_sum(array_column($reports->affiliateBreakdown($from, $to, null), 'sales'));
        $resellers = array_sum(array_column(array_filter($reports->resellerBreakdown($from, $to, null), fn ($r) => $r['reseller_id'] !== null), 'sales'));
        $this->assertGreaterThan(0, $resellers);
        $this->assertSame($total, $brands + $resellers);

        $byFilter = $reports->summary($from, $to, $s->platform->id)['total_sales'] + $reports->summary($from, $to, $s->affiliate->id)['total_sales'];
        $this->assertSame($total, $byFilter + $resellers);
        $this->assertSame([], array_filter($reports->resellerBreakdown($from, $to, $s->platform->id), fn ($r) => $r['reseller_id'] !== null));

        $storefront = Order::query()->where('affiliate_id', $s->platform->id)->whereNull('wallet_reseller_id')->where('payment_status', 'paid')->where('is_test', false);
        $analytics = app(CustomerAnalyticsService::class)->stats(new CustomerFilters(affiliateId: $s->platform->id));
        $this->assertSame((clone $storefront)->distinct()->count('customer_email'), $analytics['total_customers']);

        Sanctum::actingAs($s->admin);
        $row = collect($this->getJson('/api/affiliates')->assertOk()->json('affiliates'))->firstWhere('id', $s->platform->id);
        $this->assertSame(Order::query()->where('affiliate_id', $s->platform->id)->whereNull('wallet_reseller_id')->count(), $row['orders_count']);

        $this->assertSame(0, DB::table('llm_report_orders')->whereNotNull('affiliate_id')->whereNotNull('wallet_reseller_id')->count());
        $this->assertSame(
            (int) DB::table('llm_report_orders')->sum('net_sales'),
            (int) DB::table('llm_report_orders')->whereNotNull('affiliate_id')->sum('net_sales') + (int) DB::table('llm_report_orders')->whereNotNull('wallet_reseller_id')->sum('net_sales'),
        );
    }

    /** Membership (ADR-068): the fee is revenue the month it is paid, the same figure on the Summary and on Reports; the member's order is priced as a member's. */
    public function test_membership_revenue_agrees_and_the_member_pays_member_prices(): void
    {
        $fee = MembershipPlan::query()->where('name', 'Tier 2')->value('fee_sen');
        $september = app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9);

        $this->assertSame($fee, $september['membership_revenue_sen']);
        $this->assertSame($fee, app(ReportService::class)->membershipBreakdown(...self::month(2026, 9), affiliateId: null)['membership_fee_revenue']);
        $this->assertSame(PricingBasis::Member, $this->memberOrder->fresh()->pricing_basis);
    }

    /** An IDR supplier funded at its own deposit rates: the deposit-vs-market line is real, and COGS is per-order cost plus it. */
    public function test_an_idr_supplier_costs_at_its_funded_rate(): void
    {
        $september = app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9);

        $this->assertNotSame(0, $september['cogs_funding_difference_sen']);
        $this->assertSame($september['cogs_sen'], $september['cogs_at_order_cost_sen'] + $september['cogs_funding_difference_sen']);
        foreach ($this->idrOrders as $o) {
            $this->assertNotNull($o->fresh()->real_cost_price_sen, "order {$o->reference_number}");
        }
    }

    /** CHIP's own fee is recorded on every CHIP payment, so the processing gain or loss is measured, not assumed. */
    public function test_chip_fees_are_recorded_and_measured(): void
    {
        $missing = Order::query()->where('payment_gateway', 'chip')->where('payment_status', 'paid')->where('final_amount', '>', 0)->whereNull('gateway_fee_sen')->get(['order_number', 'payment_gateway', 'payment_ref', 'wallet_reseller_id', 'final_amount'])->toArray();
        $this->assertSame([], $missing, json_encode($missing));
        $this->assertNotSame(0, app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 9)['payment_processing_gain_loss_sen']);
    }

    /** A resend delivers the failed order instead of compensating it; an affiliate withdrawal is paid and leaves no claim. */
    public function test_a_resend_delivers_and_a_withdrawal_is_paid(): void
    {
        $resent = $this->resent->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $resent->delivery_status);
        $this->assertFalse($resent->isAlreadyCompensated());

        $this->assertSame(WithdrawalStatus::Completed, $this->withdrawal->status);
        $this->assertSame(0, app(CashPositionService::class)->asOf(2026, 10)['claims']['affiliate_withdrawals_unpaid_sen']);
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

    /**
     * ADR-131 decisions 12-18: the refund leaves the bank on the day the closure is recorded, so
     * the wallet claim and the cash fall together and the gap is unchanged; the Register shows the
     * cash that left; and a Failed wallet order nobody has refunded still blocks the closure.
     */
    public function test_closing_a_reseller_account_leaves_the_cash_equation_gap_unchanged(): void
    {
        $s = $this->scenario;
        $position = fn (): array => app(CashPositionService::class)->asOf(2026, 10);
        $gap = fn (): int => CashPositionService::gapSen($position(), $s->bankAsOf('2026-10-31'));

        $wallet = app(ResellerWalletService::class)->balance($s->reseller);
        $this->assertGreaterThan(0, $wallet);
        $gapBefore = $gap();

        $refunded = $s->at('2026-10-08 10:00')->closeResellerAccount();

        $this->assertSame($wallet, $refunded);
        $this->assertSame(0, app(ResellerWalletService::class)->balance($s->reseller));
        $this->assertSame(0, $position()['claims']['reseller_wallets_sen']);
        $this->assertSame($gapBefore, $gap(), 'the claim and the cash both fell by the refund');

        $closureRows = array_values(array_filter(
            app(TransactionRegisterService::class)->rows(CarbonImmutable::parse('2026-10-01', 'Asia/Kuala_Lumpur'), CarbonImmutable::parse('2026-11-01', 'Asia/Kuala_Lumpur')),
            fn (array $row) => $row['type'] === 'reseller_wallet_closure',
        ));
        $this->assertCount(1, $closureRows);
        $this->assertSame(-$refunded, $closureRows[0]['net_sen']);
        $this->assertSame(-$refunded, $s->bankAsOf('2026-10-08') - $s->bankAsOf('2026-10-07'));
    }

    public function test_a_failed_wallet_order_nobody_has_refunded_blocks_the_closure(): void
    {
        $s = $this->scenario;
        $s->supplierAdapter->failNext();
        $failed = $s->at('2026-10-07 09:00')->resellerApiOrder($s->packages[0]);
        $this->assertSame(DeliveryStatus::Failed, $failed->fresh()->delivery_status);
        $balance = app(ResellerWalletService::class)->balance($s->reseller);

        try {
            $s->closeResellerAccount();
            $this->fail('expected the closure to be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('reseller', $e->errors());
        }

        $this->assertSame($balance, app(ResellerWalletService::class)->balance($s->reseller));

        // Once the admin refunds it to the balance, the closure goes through.
        $s->compensate($failed);
        $this->assertGreaterThan(0, $s->closeResellerAccount());
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
        $this->assertContains($this->joki['cross_month']->order_number, $recognised);
        $this->assertCount(3, $recognised);
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
