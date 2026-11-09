<?php

namespace Tests\Feature\Rebate;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\MonthCloseService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Rebate\RebateClaimService;
use App\Services\Rebate\RebateMonthService;
use App\Services\Report\ReportService;
use App\Services\Reseller\ResellerClosureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-139 PR 3b-ii, decisions 20–23 and the accountant addendum: the rebate
 * line (revenue reduction, live for an open month, an expiry reverses it in
 * the expiry month), breakage (closure forfeit only), the payable, and the
 * month close waiting for finalise. The cash equation's rows (decision 21):
 * reached −x P&L / +x payable; claimed −x payable / +x wallet; expired +x
 * P&L / −x payable; forfeited +x P&L / −x wallet.
 */
class RebateAccountingTest extends TestCase
{
    use RefreshDatabase;

    private ResellerTier $ss;

    private Reseller $reseller;

    private AffiliateUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        AdminUser::factory()->create(['email' => 'founder@example.com', 'is_active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-12-15 02:00:00', 'UTC'));
        $this->ss = ResellerTier::query()->create(['name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1]);
        $this->reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true, 'reseller_tier_id' => $this->ss->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $this->reseller->id);
        ResellerWalletTierChange::query()->create(['reseller_id' => $this->reseller->id, 'to_tier_id' => $this->ss->id, 'effective_on' => '2026-09-01', 'applied_at' => now()]);
        $this->user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $this->reseller->id,
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'x', 'is_active' => true,
        ]);
        $this->ladder('2026-12-01');
    }

    private function ladder(string $effectiveFrom): void
    {
        ResellerRebateLadder::query()->create(['effective_from' => $effectiveFrom, 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $this->ss->id => 100]],
            ['threshold_sen' => 150000, 'rewards' => [(string) $this->ss->id => 600]],
        ]]);
    }

    private function delivered(int $sellingSen, int $profitSen, string $recognisedAtUtc = '2026-12-10 04:00:00'): Order
    {
        return Order::query()->create([
            'placed_via' => 'reseller_api', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(), 'customer_email' => 'buyer@example.com', 'player_id' => '1',
            'wallet_reseller_id' => $this->reseller->id,
            'cost_price' => $sellingSen - $profitSen, 'standard_selling_price' => $sellingSen, 'selling_price' => $sellingSen,
            'transaction_fee' => 0, 'final_amount' => $sellingSen, 'platform_profit' => $profitSen, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => $recognisedAtUtc,
        ]);
    }

    private function claim(int $step, int $month = 12): ResellerRebateMilestone
    {
        return app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, $month, $step, acceptTerms: true);
    }

    /** @return array<string, int> */
    private function lines(int $year, int $month): array
    {
        return app(MonthlyAccountingSummaryService::class)->forPeriod($year, $month);
    }

    private function payable(int $year, int $month): int
    {
        return app(CashPositionService::class)->asOf($year, $month)['claims']['reseller_rebates_payable_sen'];
    }

    private function finalizeDecember(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:10:00', 'UTC')); // 1 Jan 2027, 00:10 KL
        $this->artisan('app:finalize-rebate-months')->assertSuccessful();
    }

    public function test_the_open_month_is_live_and_a_claim_moves_the_payable_into_the_balance(): void
    {
        $this->delivered(160000, 4640); // step 2 reached, cap 1,624: entitlement 600

        $lines = $this->lines(2026, 12);
        $this->assertSame(600, $lines['reseller_rebates_sen']);
        $this->assertSame(MonthlyAccountingSummaryService::operatingProfit([...$lines, 'reseller_rebates_sen' => 0]) - 600, $lines['operating_profit_sen']);
        $this->assertSame(600, $this->payable(2026, 12));

        $this->claim(1);

        $this->assertSame(600, $this->lines(2026, 12)['reseller_rebates_sen']);
        $this->assertSame(500, $this->payable(2026, 12));
        $this->assertSame(100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $this->reseller->id));
    }

    /** Decision 12: the live figure and the finalised rows come from one function, so finalise moves nothing. */
    public function test_finalise_moves_neither_the_line_nor_the_payable(): void
    {
        $this->delivered(60000, 200); // cap 70: step 1 pays 70 of 100
        $this->claim(1);
        $this->delivered(10000, 2000, '2026-12-20 04:00:00'); // cap 770: entitlement 100

        $this->assertSame([100, 30], [$this->lines(2026, 12)['reseller_rebates_sen'], $this->payable(2026, 12)]);

        $this->finalizeDecember();

        $this->assertSame(30, ResellerRebateMilestone::query()->where('kind', ResellerRebateMilestone::KIND_ADJUSTMENT)->sole()->amount_sen);
        $this->assertSame([100, 30], [$this->lines(2026, 12)['reseller_rebates_sen'], $this->payable(2026, 12)]);
    }

    /** Stress test S2: what was paid stays on the line; never clawed back. */
    public function test_an_overpayment_after_a_negative_order_keeps_the_paid_amount_on_the_line(): void
    {
        $this->delivered(60000, 1740);
        $this->claim(1); // 100
        $this->delivered(1000, -1500, '2026-12-20 04:00:00'); // cap 84

        $this->assertSame([100, 0], [$this->lines(2026, 12)['reseller_rebates_sen'], $this->payable(2026, 12)]);
        $this->finalizeDecember();
        $this->assertSame([100, 0], [$this->lines(2026, 12)['reseller_rebates_sen'], $this->payable(2026, 12)]);
    }

    /** Accountant addendum item 2: an expiry reverses the reduction in the expiry month; the earned month never moves. */
    public function test_an_expired_reward_reverses_in_its_expiry_month(): void
    {
        $this->delivered(60000, 1740);
        $this->finalizeDecember();
        $this->assertSame([100, 100, 100], [$this->lines(2026, 12)['reseller_rebates_sen'], $this->payable(2026, 12), $this->payable(2027, 1)]);

        Carbon::setTestNow(Carbon::parse('2027-02-02 03:00:00', 'UTC'));
        $this->artisan('app:expire-rebate-rewards')->assertSuccessful();

        $this->assertSame(100, $this->lines(2026, 12)['reseller_rebates_sen']);
        $this->assertSame(0, $this->lines(2027, 1)['reseller_rebates_sen']);
        $february = $this->lines(2027, 2);
        $this->assertSame(-100, $february['reseller_rebates_sen']);
        $this->assertSame(0, $february['reseller_rebate_breakage_sen']);
        $this->assertSame(100, $this->payable(2027, 1), 'still owed at the end of January: the deadline is the first instant of February');
        $this->assertSame(0, $this->payable(2027, 2));
    }

    public function test_a_closure_forfeit_is_breakage_and_lapsed_rewards_reverse_in_the_closure_month(): void
    {
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $this->reseller->id, 5000, 'wallet_topup');
        $this->delivered(160000, 4640);
        $this->claim(1); // +100 rebate credit
        $this->finalizeDecember(); // step 2 (500) available

        Carbon::setTestNow(Carbon::parse('2027-01-10 02:00:00', 'UTC'));
        app(ResellerClosureService::class)->close($this->reseller, AdminUser::query()->first()->id, '2027-01-10', 'BANK-1');

        $january = $this->lines(2027, 1);
        $this->assertSame(100, $january['reseller_rebate_breakage_sen']);
        $this->assertSame(-500, $january['reseller_rebates_sen']);
        $this->assertSame(
            MonthlyAccountingSummaryService::operatingProfit([...$january, 'reseller_rebates_sen' => 0, 'reseller_rebate_breakage_sen' => 0]) + 500 + 100,
            $january['operating_profit_sen'],
        );
        $this->assertSame(600, $this->lines(2026, 12)['reseller_rebates_sen']);
        $this->assertSame(0, $this->payable(2027, 1));
    }

    /** Decision 23. */
    public function test_a_month_with_a_ladder_cannot_close_until_its_rebate_is_finalised(): void
    {
        $this->ladder('2026-09-01');
        Carbon::setTestNow(Carbon::parse('2026-09-30 16:05:00', 'UTC')); // 1 Oct, 00:05 KL

        $this->assertStringContainsString('rebate', (string) app(MonthCloseService::class)->preview(2026, 9)['blocked_reason']);

        app(RebateMonthService::class)->finalize(2026, 9);

        $this->assertNull(app(MonthCloseService::class)->preview(2026, 9)['blocked_reason']);
    }

    /** Invariant 2: a finalised (and so closable) month never gains a row, even if the reseller is made eligible later. */
    public function test_a_finalised_month_takes_no_claim_for_a_step_it_did_not_write(): void
    {
        $this->reseller->update(['rebate_eligible' => false]);
        $this->delivered(60000, 1740);
        $this->finalizeDecember();
        $this->reseller->update(['rebate_eligible' => true]);

        try {
            $this->claim(1);
            $this->fail('expected the claim to be refused');
        } catch (ValidationException) {
        }

        $this->assertSame(0, ResellerRebateMilestone::query()->count());
        $this->assertSame(0, $this->lines(2026, 12)['reseller_rebates_sen']);
    }

    /** Invariant 3: the Reports profit Bridge carries both rebate lines, so nothing is unexplained. */
    public function test_the_profit_bridge_explains_the_rebate_lines(): void
    {
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $this->reseller->id, 5000, 'wallet_topup');
        $this->delivered(160000, 4640);
        $this->claim(1);
        $this->finalizeDecember();
        Carbon::setTestNow(Carbon::parse('2027-01-10 02:00:00', 'UTC'));
        app(ResellerClosureService::class)->close($this->reseller, AdminUser::query()->first()->id, '2027-01-10', 'BANK-1');

        foreach ([[2026, 12, -600, 0], [2027, 1, 500, 100]] as [$year, $month, $rebates, $breakage]) {
            [$from, $to] = MonthlyAccountingSummaryService::monthBounds($year, $month);
            $bridge = app(ReportService::class)->profitBridge(CarbonImmutable::instance($from), CarbonImmutable::instance($to));
            $this->assertSame([$rebates, $breakage], [$bridge['reseller_rebates'], $bridge['reseller_rebate_breakage']], "{$year}-{$month}");
            $this->assertSame(0, $bridge['unexplained_difference'], "{$year}-{$month}");
        }
    }

    /** A reward lapsing in month M must be expired before M closes, or a late expiry run would move the closed month (review 2026-11-09). */
    public function test_a_month_cannot_close_while_a_reward_due_to_lapse_in_it_is_unexpired(): void
    {
        $this->ladder('2026-09-01');
        $this->delivered(60000, 1740, '2026-09-10 04:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-30 16:05:00', 'UTC')); // 1 Dec, 00:05 KL
        app(RebateMonthService::class)->finalizeDue(); // Sep, Oct, Nov; September's step lapsed at 00:00 KL on 1 Nov
        AccountingPeriodClose::query()->create([
            'period_month' => '2026-10-01', 'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0,
            'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0, 'closed_by' => AdminUser::query()->first()->id,
        ]);

        $this->assertStringContainsString('expired', (string) app(MonthCloseService::class)->preview(2026, 11)['blocked_reason']);

        $this->artisan('app:expire-rebate-rewards')->assertSuccessful();

        $this->assertNull(app(MonthCloseService::class)->preview(2026, 11)['blocked_reason']);
    }
}
