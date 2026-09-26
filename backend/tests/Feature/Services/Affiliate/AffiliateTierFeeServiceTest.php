<?php

namespace Tests\Feature\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Affiliate\AffiliateTierFeeService;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the affiliate wholesale-tier
 * fee charge + grace/lapse state machine. The fee is collected from the
 * affiliate's EARNINGS ledger balance — no prepaid deposit wallet exists
 * in this phase (decision 7).
 */
class AffiliateTierFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AffiliateTierFeeService
    {
        return app(AffiliateTierFeeService::class);
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Test Affiliate',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function tier(int $feeSen = 5000, float $markupPercent = 5.0): AffiliateMembershipTier
    {
        return AffiliateMembershipTier::query()->create([
            'name' => 'Silver',
            'monthly_fee_sen' => $feeSen,
            'markup_percent' => $markupPercent,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function subscription(Affiliate $affiliate, AffiliateMembershipTier $tier, array $overrides = []): AffiliateSubscription
    {
        return AffiliateSubscription::query()->create(array_merge([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
            'grace_until' => null,
        ], $overrides));
    }

    private function fundEarnings(Affiliate $affiliate, int $sen): void
    {
        $this->ledger()->openAccount('affiliate', $affiliate->id);
        $this->ledger()->credit('affiliate', $affiliate->id, $sen, 'order_profit', 'order', 1);
    }

    public function test_sufficient_earnings_debits_the_fee_and_advances_the_cycle(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier);
        $this->fundEarnings($affiliate, 12000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Active, $result->status);
        $this->assertNull($result->grace_until);
        $this->assertTrue($result->next_charge_at->between(now()->addDays(29), now()->addDays(31)));

        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'type' => 'affiliate_tier_fee',
            'amount' => -5000,
            'reference_type' => 'affiliate_subscription',
            'reference_id' => $subscription->id,
        ]);

        $this->assertSame(7000, $this->ledger()->balance('affiliate', $affiliate->id));
    }

    public function test_insufficient_earnings_moves_to_grace_without_a_ledger_write(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier);
        $this->fundEarnings($affiliate, 1000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Grace, $result->status);
        $this->assertTrue($result->grace_until->between(now()->addDays(2), now()->addDays(4)));
        $this->assertDatabaseMissing('ledger_entries', [
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'type' => 'affiliate_tier_fee',
        ]);
        $this->assertSame(1000, $this->ledger()->balance('affiliate', $affiliate->id));
    }

    public function test_a_affiliate_with_no_ledger_account_yet_moves_to_grace_not_error(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Grace, $result->status);
    }

    public function test_grace_retry_does_not_extend_grace_until(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $graceUntil = now()->addDay()->startOfSecond();
        $subscription = $this->subscription($affiliate, $tier, [
            'status' => AffiliateSubscriptionStatus::Grace,
            'grace_until' => $graceUntil,
        ]);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Grace, $result->status);
        $this->assertEquals($graceUntil, $result->grace_until->startOfSecond());
    }

    public function test_grace_retry_with_now_sufficient_earnings_charges_and_reactivates(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier, [
            'status' => AffiliateSubscriptionStatus::Grace,
            'grace_until' => now()->addDay(),
        ]);
        $this->fundEarnings($affiliate, 8000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Active, $result->status);
        $this->assertNull($result->grace_until);
        $this->assertSame(3000, $this->ledger()->balance('affiliate', $affiliate->id));
    }

    public function test_grace_elapsed_lapses_without_charging(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier, [
            'status' => AffiliateSubscriptionStatus::Grace,
            'grace_until' => now()->subDay(),
        ]);
        $this->fundEarnings($affiliate, 50000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Lapsed, $result->status);
        $this->assertDatabaseMissing('ledger_entries', [
            'owner_type' => 'affiliate',
            'type' => 'affiliate_tier_fee',
        ]);
    }

    public function test_lapsed_subscription_is_a_noop(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier, [
            'status' => AffiliateSubscriptionStatus::Lapsed,
        ]);
        $this->fundEarnings($affiliate, 50000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Lapsed, $result->status);
        $this->assertSame(50000, $this->ledger()->balance('affiliate', $affiliate->id));
    }

    /**
     * Item 33 (2026-09-26 audit): a second chargeCycle() call for a
     * subscription already charged this cycle (e.g. the scheduled command
     * racing an admin's manual "Charge Now", which has no due-date filter
     * of its own) must no-op rather than debit the fee again, even when
     * the affiliate has more than enough earnings for a second charge.
     */
    public function test_charging_an_already_charged_cycle_again_is_a_noop(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier);
        $this->fundEarnings($affiliate, 20000);

        $first = $this->service()->chargeCycle($subscription);
        $this->assertSame(AffiliateSubscriptionStatus::Active, $first->status);
        $this->assertSame(15000, $this->ledger()->balance('affiliate', $affiliate->id));

        $second = $this->service()->chargeCycle($first);

        $this->assertSame(AffiliateSubscriptionStatus::Active, $second->status);
        $this->assertSame(15000, $this->ledger()->balance('affiliate', $affiliate->id));
        $this->assertSame(
            1,
            \App\Models\LedgerEntry::query()
                ->where('owner_type', 'affiliate')
                ->where('owner_id', $affiliate->id)
                ->where('type', 'affiliate_tier_fee')
                ->count(),
        );
    }

    /**
     * Item 33 (2026-09-26 audit): the eager-loaded `tier` relation must
     * include a soft-deleted tier — without `withTrashed()`, a
     * subscription whose tier was later deleted resolved `tier` to null,
     * silently charging RM0 (`(int) null`) instead of the real fee.
     */
    public function test_a_soft_deleted_tier_still_charges_its_own_fee(): void
    {
        $affiliate = $this->affiliate();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($affiliate, $tier);
        $this->fundEarnings($affiliate, 12000);

        $tier->delete();

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(AffiliateSubscriptionStatus::Active, $result->status);
        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'type' => 'affiliate_tier_fee',
            'amount' => -5000,
        ]);
        $this->assertSame(7000, $this->ledger()->balance('affiliate', $affiliate->id));
    }
}
