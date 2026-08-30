<?php

namespace Tests\Feature\Console;

use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerSubscription;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the scheduled command that
 * sweeps due reseller wholesale-tier subscriptions.
 */
class ChargeResellerTierFeesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tier(int $feeSen = 5000): ResellerMembershipTier
    {
        return ResellerMembershipTier::query()->create([
            'name' => 'Silver',
            'monthly_fee_sen' => $feeSen,
            'markup_percent' => 5.0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function resellerWithSubscription(array $overrides = [], ?int $fundSen = null): ResellerSubscription
    {
        $reseller = Reseller::query()->create([
            'business_name' => 'R'.uniqid(),
            'markup_pct' => 10,
            'status' => 'active',
        ]);

        if ($fundSen !== null) {
            $ledger = app(LedgerService::class);
            $ledger->openAccount('reseller', $reseller->id);
            $ledger->credit('reseller', $reseller->id, $fundSen, 'order_profit', 'order', 1);
        }

        return ResellerSubscription::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'reseller_membership_tier_id' => $this->tier()->id,
            'status' => ResellerSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_charges_due_active_subscriptions_and_leaves_not_yet_due_ones_alone(): void
    {
        $due = $this->resellerWithSubscription(fundSen: 20000);
        $notDue = $this->resellerWithSubscription(['next_charge_at' => now()->addDays(10)], fundSen: 20000);

        $this->artisan('app:charge-reseller-tier-fees')
            ->expectsOutputToContain('1 charged')
            ->assertSuccessful();

        $this->assertSame(15000, app(LedgerService::class)->balance('reseller', $due->reseller_id));
        $this->assertSame(20000, app(LedgerService::class)->balance('reseller', $notDue->reseller_id));
        $this->assertTrue($due->refresh()->next_charge_at->isFuture());
    }

    public function test_underfunded_due_subscription_enters_grace(): void
    {
        $sub = $this->resellerWithSubscription(fundSen: 100);

        $this->artisan('app:charge-reseller-tier-fees')
            ->expectsOutputToContain('1 entered grace')
            ->assertSuccessful();

        $this->assertSame(ResellerSubscriptionStatus::Grace, $sub->refresh()->status);
    }

    public function test_grace_subscription_past_grace_until_lapses(): void
    {
        $sub = $this->resellerWithSubscription([
            'status' => ResellerSubscriptionStatus::Grace,
            'grace_until' => now()->subDay(),
            'next_charge_at' => now()->addDays(20),
        ], fundSen: 0);

        $this->artisan('app:charge-reseller-tier-fees')
            ->expectsOutputToContain('1 lapsed')
            ->assertSuccessful();

        $this->assertSame(ResellerSubscriptionStatus::Lapsed, $sub->refresh()->status);
    }

    public function test_lapsed_subscriptions_are_not_touched(): void
    {
        $sub = $this->resellerWithSubscription([
            'status' => ResellerSubscriptionStatus::Lapsed,
            'next_charge_at' => now()->subDays(40),
        ], fundSen: 50000);

        $this->artisan('app:charge-reseller-tier-fees')->assertSuccessful();

        $this->assertSame(ResellerSubscriptionStatus::Lapsed, $sub->refresh()->status);
        $this->assertSame(50000, app(LedgerService::class)->balance('reseller', $sub->reseller_id));
    }
}
