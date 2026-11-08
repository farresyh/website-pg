<?php

namespace Tests\Feature\Rebate;

use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Rebate\RebateClaimService;
use App\Services\Rebate\RebateEntitlement;
use App\Services\Rebate\RebateLadderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** ADR-139 PR 3a: the entitlement function and the claim, the only writer of wallet_rebate. */
class RebateClaimTest extends TestCase
{
    use RefreshDatabase;

    private ResellerTier $ss;

    private Reseller $reseller;

    private AffiliateUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15 02:00:00', 'UTC')); // 15 Dec 2026, 10:00 KL
        $this->ss = ResellerTier::query()->create(['name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1]);
        $this->reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true, 'reseller_tier_id' => $this->ss->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $this->reseller->id);
        ResellerWalletTierChange::query()->create(['reseller_id' => $this->reseller->id, 'to_tier_id' => $this->ss->id, 'effective_on' => '2026-11-01', 'applied_at' => now()]);
        $this->user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $this->reseller->id,
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'x', 'is_active' => true,
        ]);
        // RM500 / RM1,500 / RM3,000 → cumulative RM1 / RM6 / RM17 on SS; cap 35% of profit.
        ResellerRebateLadder::query()->create(['effective_from' => '2026-12-01', 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $this->ss->id => 100]],
            ['threshold_sen' => 150000, 'rewards' => [(string) $this->ss->id => 600]],
            ['threshold_sen' => 300000, 'rewards' => [(string) $this->ss->id => 1700]],
        ]]);
    }

    private function delivered(int $sellingSen, int $profitSen, string $recognisedAtUtc = '2026-12-10 04:00:00', array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'reseller_api', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(), 'customer_email' => 'buyer@example.com', 'player_id' => '1',
            'wallet_reseller_id' => $this->reseller->id,
            'cost_price' => $sellingSen - $profitSen, 'standard_selling_price' => $sellingSen, 'selling_price' => $sellingSen,
            'transaction_fee' => 0, 'final_amount' => $sellingSen, 'platform_profit' => $profitSen, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => $recognisedAtUtc,
        ], $overrides));
    }

    private function claim(int $step, int $year = 2026, int $month = 12): ResellerRebateMilestone
    {
        return app(RebateClaimService::class)->claim($this->reseller, $this->user, $year, $month, $step, acceptTerms: true);
    }

    private function balance(): int
    {
        return app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $this->reseller->id);
    }

    private function assertRefused(callable $fn, string $field = 'rebate'): void
    {
        try {
            $fn();
            $this->fail('Expected the claim to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_a_reached_step_is_credited_once_with_its_calculation_inputs(): void
    {
        $this->delivered(60000, 1740);

        $row = $this->claim(1);

        $this->assertSame(100, $this->balance());
        $this->assertSame(ResellerRebateMilestone::CLAIMED, $row->state);
        $this->assertFalse($row->adjusted);
        $this->assertSame([60000, 1740, 100, 609], [$row->sales_sen, $row->profit_sen, $row->ladder_cumulative_sen, $row->cap_sen]);
        $this->assertDatabaseHas('ledger_entries', ['type' => 'wallet_rebate', 'amount' => 100, 'reference_type' => 'rebate_milestone', 'reference_id' => $row->id]);
        $this->assertDatabaseHas('reseller_rebate_terms_acceptances', ['reseller_id' => $this->reseller->id, 'terms_version' => 1, 'affiliate_user_id' => $this->user->id]);

        $this->assertRefused(fn () => $this->claim(1));
        $this->assertSame(1, LedgerEntry::query()->where('type', 'wallet_rebate')->count());
    }

    public function test_steps_are_claimed_in_order_and_only_once_reached(): void
    {
        $this->delivered(160000, 4640);

        $this->assertRefused(fn () => $this->claim(2));
        $this->assertRefused(fn () => $this->claim(3));
        $this->claim(1);
        $this->claim(2);

        $this->assertSame(600, $this->balance());
    }

    /** Stress test F1: a cut on an early claim is paid back by the next one, never "claim late, get more". */
    public function test_a_capped_claim_is_marked_adjusted_and_the_shortfall_comes_back_on_the_next_claim(): void
    {
        $this->delivered(160000, 1000); // cap = 350 sen

        $this->claim(1);
        $second = $this->claim(2);
        $this->assertSame(250, $second->amount_sen); // min(600, 350) − 100
        $this->assertTrue($second->adjusted);

        $this->delivered(150000, 4000); // sales RM3,100 → step 3; profit 5,000 → cap 1,750
        $third = $this->claim(3);

        $this->assertSame(1350, $third->amount_sen); // min(1700, 1750) − 350
        $this->assertSame(1700, $this->balance());
    }

    public function test_negative_profit_never_pays(): void
    {
        $this->delivered(60000, -13);

        $row = $this->claim(1);

        $this->assertSame(0, $row->amount_sen);
        $this->assertTrue($row->adjusted);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_the_terms_must_be_accepted_once_per_version(): void
    {
        $this->delivered(160000, 4640);

        $this->assertRefused(fn () => app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 1), 'terms');
        $this->claim(1);
        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 2);

        $this->assertSame(600, $this->balance());
    }

    public function test_the_claim_window_closes_at_the_end_of_the_next_month_kl(): void
    {
        $this->delivered(60000, 1740);

        Carbon::setTestNow(Carbon::parse('2027-01-31 15:59:00', 'UTC')); // 31 Jan 23:59 KL
        $this->claim(1);

        $this->delivered(100000, 2900);
        Carbon::setTestNow(Carbon::parse('2027-01-31 16:00:00', 'UTC')); // 1 Feb 00:00 KL
        $this->assertRefused(fn () => $this->claim(2));
    }

    public function test_a_future_month_and_orders_outside_the_month_do_not_count(): void
    {
        $this->delivered(60000, 1740, '2026-11-30 15:59:59'); // 30 Nov 23:59 KL
        $this->assertRefused(fn () => $this->claim(1));
        $this->assertRefused(fn () => $this->claim(1, 2027, 1));

        $this->delivered(60000, 1740, '2026-11-30 16:00:00'); // 1 Dec 00:00 KL
        $this->claim(1);
        $this->assertSame(100, $this->balance());
    }

    public function test_sandbox_orders_do_not_count(): void
    {
        $this->delivered(60000, 1740, overrides: ['is_test' => true]);

        $this->assertRefused(fn () => $this->claim(1));
    }

    public function test_a_deactivated_ineligible_or_foreign_login_cannot_claim(): void
    {
        $this->delivered(60000, 1740);

        $this->reseller->update(['rebate_eligible' => false]);
        $this->assertRefused(fn () => $this->claim(1));
        $this->reseller->update(['rebate_eligible' => true, 'is_active' => false]);
        $this->assertRefused(fn () => $this->claim(1));
        $this->reseller->update(['is_active' => true]);

        $other = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        $this->user->update(['owner_id' => $other->id]);
        $this->assertRefused(fn () => $this->claim(1));
        $this->assertSame(0, $this->balance());
    }

    public function test_a_tier_with_no_ladder_column_earns_nothing(): void
    {
        $vip = ResellerTier::query()->create(['name' => 'VIP', 'markup_percent' => 1.5, 'is_active' => true, 'sort_order' => 2]);
        ResellerWalletTierChange::query()->create(['reseller_id' => $this->reseller->id, 'from_tier_id' => $this->ss->id, 'to_tier_id' => $vip->id, 'effective_on' => '2026-12-01', 'applied_at' => now()]);
        $this->delivered(60000, 1740);

        $this->assertRefused(fn () => $this->claim(1));
    }

    /** Decision 7: the month's tier is read from history, so a change from 1 Jan leaves December on SS. */
    public function test_the_months_tier_comes_from_history_not_the_current_column(): void
    {
        $vip = ResellerTier::query()->create(['name' => 'VIP', 'markup_percent' => 1.5, 'is_active' => true, 'sort_order' => 2]);
        ResellerWalletTierChange::query()->create(['reseller_id' => $this->reseller->id, 'from_tier_id' => $this->ss->id, 'to_tier_id' => $vip->id, 'effective_on' => '2027-01-01']);
        $this->reseller->update(['reseller_tier_id' => $vip->id]);

        $standing = app(RebateEntitlement::class)->standing($this->reseller, 2026, 12);

        $this->assertSame($this->ss->id, $standing->tierId);
    }

    public function test_a_ladder_version_needs_seven_days_notice_and_stays_within_the_ceiling(): void
    {
        $ladders = app(RebateLadderService::class);
        $grid = fn (int $reward) => [['threshold_sen' => 50000, 'rewards' => [$this->ss->id => $reward]]];

        // 15 Dec → 1 Jan is 17 days away.
        $this->assertSame('2027-01-01', $ladders->saveVersion($grid(100), 3500, null)->effective_from->toDateString());
        // 26 Dec → 1 Jan is 6 days away, so 1 Feb.
        Carbon::setTestNow(Carbon::parse('2026-12-26 02:00:00', 'UTC'));
        $this->assertSame('2027-02-01', $ladders->saveVersion($grid(100), 3500, null)->effective_from->toDateString());

        // RM500 at 3% earns ~RM14.56; half is ~RM7.28.
        $this->expectException(ValidationException::class);
        $ladders->saveVersion($grid(800), 3500, null);
    }

    public function test_the_cap_can_never_exceed_the_hard_ceiling(): void
    {
        $this->expectException(ValidationException::class);
        app(RebateLadderService::class)->saveVersion([['threshold_sen' => 50000, 'rewards' => [$this->ss->id => 100]]], 5001, null);
    }

    public function test_falling_rewards_and_unordered_thresholds_are_refused(): void
    {
        $ladders = app(RebateLadderService::class);

        foreach ([
            [['threshold_sen' => 150000, 'rewards' => [$this->ss->id => 600]], ['threshold_sen' => 50000, 'rewards' => [$this->ss->id => 700]]],
            [['threshold_sen' => 50000, 'rewards' => [$this->ss->id => 600]], ['threshold_sen' => 150000, 'rewards' => [$this->ss->id => 500]]],
        ] as $steps) {
            try {
                $ladders->saveVersion($steps, 3500, null);
                $this->fail('A bad ladder was saved.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, ResellerRebateLadder::query()->count());
    }
}
