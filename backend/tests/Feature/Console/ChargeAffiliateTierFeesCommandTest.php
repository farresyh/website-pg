<?php

namespace Tests\Feature\Console;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the scheduled command that
 * sweeps due affiliate wholesale-tier subscriptions.
 */
class ChargeAffiliateTierFeesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tier(int $feeSen = 5000): AffiliateMembershipTier
    {
        return AffiliateMembershipTier::query()->create([
            'name' => 'Silver',
            'monthly_fee_sen' => $feeSen,
            'markup_percent' => 5.0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function affiliateWithSubscription(array $overrides = [], ?int $fundSen = null): AffiliateSubscription
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'R'.uniqid(),
            'markup_pct' => 10,
            'status' => 'active',
        ]);

        if ($fundSen !== null) {
            $ledger = app(LedgerService::class);
            $ledger->openAccount('affiliate', $affiliate->id);
            $ledger->credit('affiliate', $affiliate->id, $fundSen, 'order_profit', 'order', 1);
        }

        return AffiliateSubscription::query()->create(array_merge([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $this->tier()->id,
            'status' => AffiliateSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_charges_due_active_subscriptions_and_leaves_not_yet_due_ones_alone(): void
    {
        $due = $this->affiliateWithSubscription(fundSen: 20000);
        $notDue = $this->affiliateWithSubscription(['next_charge_at' => now()->addDays(10)], fundSen: 20000);

        $this->artisan('app:charge-affiliate-tier-fees')
            ->expectsOutputToContain('1 charged')
            ->assertSuccessful();

        $this->assertSame(15000, app(LedgerService::class)->balance('affiliate', $due->affiliate_id));
        $this->assertSame(20000, app(LedgerService::class)->balance('affiliate', $notDue->affiliate_id));
        $this->assertTrue($due->refresh()->next_charge_at->isFuture());
    }

    public function test_underfunded_due_subscription_enters_grace(): void
    {
        $sub = $this->affiliateWithSubscription(fundSen: 100);

        $this->artisan('app:charge-affiliate-tier-fees')
            ->expectsOutputToContain('1 entered grace')
            ->assertSuccessful();

        $this->assertSame(AffiliateSubscriptionStatus::Grace, $sub->refresh()->status);
    }

    public function test_grace_subscription_past_grace_until_lapses(): void
    {
        $sub = $this->affiliateWithSubscription([
            'status' => AffiliateSubscriptionStatus::Grace,
            'grace_until' => now()->subDay(),
            'next_charge_at' => now()->addDays(20),
        ], fundSen: 0);

        $this->artisan('app:charge-affiliate-tier-fees')
            ->expectsOutputToContain('1 lapsed')
            ->assertSuccessful();

        $this->assertSame(AffiliateSubscriptionStatus::Lapsed, $sub->refresh()->status);
    }

    public function test_lapsed_subscriptions_are_not_touched(): void
    {
        $sub = $this->affiliateWithSubscription([
            'status' => AffiliateSubscriptionStatus::Lapsed,
            'next_charge_at' => now()->subDays(40),
        ], fundSen: 50000);

        $this->artisan('app:charge-affiliate-tier-fees')->assertSuccessful();

        $this->assertSame(AffiliateSubscriptionStatus::Lapsed, $sub->refresh()->status);
        $this->assertSame(50000, app(LedgerService::class)->balance('affiliate', $sub->affiliate_id));
    }
}
