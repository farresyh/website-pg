<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerSubscription;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerSubscriptionStatus;
use App\Services\Reseller\ResellerTierFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the reseller wholesale-tier
 * fee charge + grace/lapse state machine. The fee is collected from the
 * reseller's EARNINGS ledger balance — no prepaid deposit wallet exists
 * in this phase (decision 7).
 */
class ResellerTierFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ResellerTierFeeService
    {
        return app(ResellerTierFeeService::class);
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function reseller(): Reseller
    {
        return Reseller::query()->create([
            'business_name' => 'Test Reseller',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function tier(int $feeSen = 5000, float $markupPercent = 5.0): ResellerMembershipTier
    {
        return ResellerMembershipTier::query()->create([
            'name' => 'Silver',
            'monthly_fee_sen' => $feeSen,
            'markup_percent' => $markupPercent,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function subscription(Reseller $reseller, ResellerMembershipTier $tier, array $overrides = []): ResellerSubscription
    {
        return ResellerSubscription::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'reseller_membership_tier_id' => $tier->id,
            'status' => ResellerSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
            'grace_until' => null,
        ], $overrides));
    }

    private function fundEarnings(Reseller $reseller, int $sen): void
    {
        $this->ledger()->openAccount('reseller', $reseller->id);
        $this->ledger()->credit('reseller', $reseller->id, $sen, 'order_profit', 'order', 1);
    }

    public function test_sufficient_earnings_debits_the_fee_and_advances_the_cycle(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier);
        $this->fundEarnings($reseller, 12000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Active, $result->status);
        $this->assertNull($result->grace_until);
        $this->assertTrue($result->next_charge_at->between(now()->addDays(29), now()->addDays(31)));

        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => 'reseller',
            'owner_id' => $reseller->id,
            'type' => 'reseller_tier_fee',
            'amount' => -5000,
            'reference_type' => 'reseller_subscription',
            'reference_id' => $subscription->id,
        ]);

        $this->assertSame(7000, $this->ledger()->balance('reseller', $reseller->id));
    }

    public function test_insufficient_earnings_moves_to_grace_without_a_ledger_write(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier);
        $this->fundEarnings($reseller, 1000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Grace, $result->status);
        $this->assertTrue($result->grace_until->between(now()->addDays(2), now()->addDays(4)));
        $this->assertDatabaseMissing('ledger_entries', [
            'owner_type' => 'reseller',
            'owner_id' => $reseller->id,
            'type' => 'reseller_tier_fee',
        ]);
        $this->assertSame(1000, $this->ledger()->balance('reseller', $reseller->id));
    }

    public function test_a_reseller_with_no_ledger_account_yet_moves_to_grace_not_error(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Grace, $result->status);
    }

    public function test_grace_retry_does_not_extend_grace_until(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $graceUntil = now()->addDay()->startOfSecond();
        $subscription = $this->subscription($reseller, $tier, [
            'status' => ResellerSubscriptionStatus::Grace,
            'grace_until' => $graceUntil,
        ]);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Grace, $result->status);
        $this->assertEquals($graceUntil, $result->grace_until->startOfSecond());
    }

    public function test_grace_retry_with_now_sufficient_earnings_charges_and_reactivates(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier, [
            'status' => ResellerSubscriptionStatus::Grace,
            'grace_until' => now()->addDay(),
        ]);
        $this->fundEarnings($reseller, 8000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Active, $result->status);
        $this->assertNull($result->grace_until);
        $this->assertSame(3000, $this->ledger()->balance('reseller', $reseller->id));
    }

    public function test_grace_elapsed_lapses_without_charging(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier, [
            'status' => ResellerSubscriptionStatus::Grace,
            'grace_until' => now()->subDay(),
        ]);
        $this->fundEarnings($reseller, 50000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Lapsed, $result->status);
        $this->assertDatabaseMissing('ledger_entries', [
            'owner_type' => 'reseller',
            'type' => 'reseller_tier_fee',
        ]);
    }

    public function test_lapsed_subscription_is_a_noop(): void
    {
        $reseller = $this->reseller();
        $tier = $this->tier(feeSen: 5000);
        $subscription = $this->subscription($reseller, $tier, [
            'status' => ResellerSubscriptionStatus::Lapsed,
        ]);
        $this->fundEarnings($reseller, 50000);

        $result = $this->service()->chargeCycle($subscription);

        $this->assertSame(ResellerSubscriptionStatus::Lapsed, $result->status);
        $this->assertSame(50000, $this->ledger()->balance('reseller', $reseller->id));
    }
}
