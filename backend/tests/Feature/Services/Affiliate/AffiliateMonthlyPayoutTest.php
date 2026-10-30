<?php

namespace Tests\Feature\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Affiliate\AffiliateTierFeeService;
use App\Services\Affiliate\AffiliateWithdrawalService;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-132: the monthly auto-payout. "Now" is pinned to 2026-11-02 10:00
 * Malaysia time, so the cut-off is 2026-11-01 00:00 MYT (2026-10-31 16:00
 * UTC) and the next cut-off is 2026-12-01 00:00 MYT.
 */
class AffiliateMonthlyPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOFF_UTC = '2026-10-31 16:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Asia/Kuala_Lumpur'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): AffiliateWithdrawalService
    {
        return app(AffiliateWithdrawalService::class);
    }

    private function affiliate(bool $bank = true): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Acme',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
            'bank_name' => $bank ? 'Maybank' : null,
            'bank_account_no' => $bank ? '1234567' : null,
            'bank_account_holder' => $bank ? 'Acme Sdn Bhd' : null,
        ]);
    }

    private function earn(Affiliate $a, int $sen, string $at = '2026-10-20 00:00:00'): void
    {
        app(LedgerService::class)->openAccount('affiliate', $a->id);
        $entry = app(LedgerService::class)->credit('affiliate', $a->id, $sen, 'order_profit', 'order', random_int(1, 99999));
        LedgerEntry::query()->whereKey($entry->id)->update(['created_at' => $at]);
    }

    private function subscribe(Affiliate $a, int $feeSen, string $nextChargeAt, AffiliateSubscriptionStatus $status = AffiliateSubscriptionStatus::Active): AffiliateSubscription
    {
        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'T'.$feeSen, 'monthly_fee_sen' => $feeSen, 'markup_percent' => 5,
            'is_active' => true, 'sort_order' => 1,
        ]);

        return AffiliateSubscription::query()->create([
            'affiliate_id' => $a->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => $status,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => Carbon::parse($nextChargeAt, 'UTC'),
            'grace_until' => $status === AffiliateSubscriptionStatus::Grace ? now()->addDay() : null,
        ]);
    }

    public function test_opens_one_pending_ticket_for_the_whole_balance(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);

        $this->svc()->openMonthly($a);

        $w = Withdrawal::query()->sole();
        $this->assertSame(25000, $w->amount);
        $this->assertSame('2026-11', $w->payout_period);
        $this->assertSame(WithdrawalStatus::Pending, $w->status);
        $this->assertNull($w->requested_by);
        $this->assertSame('Maybank', $w->bank_name);
    }

    public function test_second_run_in_the_same_month_changes_nothing(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);

        $this->svc()->openMonthly($a);
        $this->svc()->openMonthly($a);

        $this->assertSame(1, Withdrawal::query()->count());
    }

    public function test_a_rejected_ticket_is_not_reopened_the_same_month(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $this->svc()->openMonthly($a);
        Withdrawal::query()->update(['status' => WithdrawalStatus::Rejected]);

        $this->svc()->openMonthly($a);

        $this->assertSame(1, Withdrawal::query()->count());
    }

    public function test_orders_after_the_cutoff_belong_to_next_month(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000, '2026-10-31 15:59:59');
        $this->earn($a, 9000, self::CUTOFF_UTC); // exactly the cut-off: next month

        $this->svc()->openMonthly($a);

        $this->assertSame(25000, Withdrawal::query()->sole()->amount);
    }

    public function test_below_minimum_opens_nothing_and_exactly_the_minimum_opens(): void
    {
        $low = $this->affiliate();
        $this->earn($low, 999);
        $this->assertSame('below_min', $this->svc()->plan($low)['skip']);
        $this->svc()->openMonthly($low);
        $this->assertSame(0, Withdrawal::query()->count());

        $edge = $this->affiliate();
        $this->earn($edge, 1000);
        $this->svc()->openMonthly($edge);
        $this->assertSame(1000, Withdrawal::query()->sole()->amount);
    }

    public function test_missing_bank_details_skip_the_affiliate(): void
    {
        $a = $this->affiliate(bank: false);
        $this->earn($a, 25000);

        $this->assertSame('no_bank', $this->svc()->plan($a)['skip']);
        $this->svc()->openMonthly($a);
        $this->assertSame(0, Withdrawal::query()->count());
    }

    public function test_a_pending_ticket_from_an_earlier_month_blocks_but_an_approved_one_does_not(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $old = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $a->id, 'payout_period' => '2026-10', 'amount' => 1000,
            'bank_name' => 'M', 'bank_account_no' => '1', 'bank_account_holder' => 'A',
            'status' => WithdrawalStatus::Pending,
        ]);

        $this->assertSame('open_ticket', $this->svc()->plan($a)['skip']);

        $old->update(['status' => WithdrawalStatus::Approved]);
        $this->assertNull($this->svc()->plan($a)['skip']);
    }

    public function test_tier_fee_due_this_month_is_held_back(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $this->subscribe($a, 3000, '2026-11-15 00:00:00');

        $this->svc()->openMonthly($a);

        $this->assertSame(22000, Withdrawal::query()->sole()->amount);
    }

    public function test_fee_due_after_next_cutoff_is_not_reserved(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $this->subscribe($a, 3000, '2026-12-05 00:00:00');

        $this->assertSame(0, $this->svc()->plan($a)['reserve']);
    }

    public function test_fees_straddling_the_next_cutoff_reserve_only_those_before_it(): void
    {
        // Nov has 30 days: next cut-off is 2026-11-30 16:00 UTC. A charge at or
        // after it, or whose +30d lands on it, still counts once.
        foreach (['2026-10-31 17:00:00', '2026-10-31 16:00:00', '2026-10-30 00:00:00'] as $due) {
            $a = $this->affiliate();
            $this->earn($a, 25000);
            $this->subscribe($a, 3000, $due);
            $this->assertSame(3000, $this->svc()->plan($a)['reserve'], $due);
        }
    }

    public function test_a_31_day_month_can_hold_two_charges_of_a_30_day_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-02 10:00:00', 'Asia/Kuala_Lumpur')); // cut-off 2026-11-30 16:00 UTC, next 2026-12-31 16:00 UTC

        $two = $this->affiliate();
        $this->earn($two, 25000, '2026-11-20 00:00:00');
        $this->subscribe($two, 3000, '2026-11-30 17:00:00'); // +30d = 12-30 17:00: still before the next cut-off
        $this->assertSame(6000, $this->svc()->plan($two)['reserve']);

        $one = $this->affiliate();
        $this->earn($one, 25000, '2026-11-20 00:00:00');
        $this->subscribe($one, 3000, '2026-12-01 17:00:00'); // +30d = 12-31 17:00: after it
        $this->assertSame(3000, $this->svc()->plan($one)['reserve']);
    }

    public function test_free_tier_lapsed_and_no_subscription_reserve_nothing_and_grace_reserves_a_fee(): void
    {
        $free = $this->affiliate();
        $this->earn($free, 25000);
        $this->subscribe($free, 0, '2026-11-15 00:00:00');
        $this->assertSame(0, $this->svc()->plan($free)['reserve']);

        $lapsed = $this->affiliate();
        $this->earn($lapsed, 25000);
        $this->subscribe($lapsed, 3000, '2026-11-15 00:00:00', AffiliateSubscriptionStatus::Lapsed);
        $this->assertSame(0, $this->svc()->plan($lapsed)['reserve']);

        $none = $this->affiliate();
        $this->earn($none, 25000);
        $this->assertSame(0, $this->svc()->plan($none)['reserve']);

        $grace = $this->affiliate();
        $this->earn($grace, 25000);
        $this->subscribe($grace, 3000, '2026-10-25 00:00:00', AffiliateSubscriptionStatus::Grace);
        $this->assertGreaterThanOrEqual(3000, $this->svc()->plan($grace)['reserve']);
    }

    public function test_a_fee_charged_after_the_cutoff_is_not_double_counted(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $sub = $this->subscribe($a, 3000, '2026-11-01 00:00:00'); // due between cut-off and run
        app(AffiliateTierFeeService::class)->chargeCycle($sub); // debits 3000 now; next_charge_at moves +30d

        $this->svc()->openMonthly($a);

        // current balance 22000 < cut-off balance 25000; the fee is already paid, nothing further reserved
        $this->assertSame(22000, Withdrawal::query()->sole()->amount);
    }

    public function test_approving_the_ticket_leaves_enough_for_the_fee_that_follows(): void
    {
        $a = $this->affiliate();
        $this->earn($a, 25000);
        $sub = $this->subscribe($a, 3000, '2026-11-05 00:00:00');
        $this->svc()->openMonthly($a);
        $w = Withdrawal::query()->sole();

        app(LedgerService::class)->withdraw('affiliate', $a->id, $w->amount, referenceType: 'withdrawal', referenceId: $w->id);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'UTC'));
        $sub = app(AffiliateTierFeeService::class)->chargeCycle($sub);

        $this->assertSame(AffiliateSubscriptionStatus::Active, $sub->status);
        $this->assertSame(0, app(LedgerService::class)->balance('affiliate', $a->id));
    }

    public function test_open_all_continues_past_a_skipped_affiliate(): void
    {
        $skipped = $this->affiliate(bank: false);
        $this->earn($skipped, 25000);
        $ok = $this->affiliate();
        $this->earn($ok, 30000);

        $this->svc()->openAll();

        $this->assertSame([$ok->id], Withdrawal::query()->pluck('owner_id')->all());
    }
}
