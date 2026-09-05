<?php

namespace Tests\Feature\Console;

use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 base decision 7 / Phase 6 — the rolling-30-day quota refill.
 */
class ResetMembershipCyclesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function membership(array $overrides = []): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return Membership::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 500, // partially spent
            'expires_at' => now()->addDays(60),
        ], $overrides));
    }

    public function test_refills_and_advances_the_cycle_for_a_membership_31_days_in(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = $this->membership();
        $membership->forceFill(['cycle_started_at' => now()->subDays(31)])->save();

        $this->artisan('app:reset-membership-cycles')->assertExitCode(0);

        $fresh = $membership->fresh();
        $this->assertSame($plan->quota_sen, $fresh->quota_remaining_sen);
        $this->assertTrue($fresh->cycle_started_at->greaterThan(now()->subMinute()));
    }

    public function test_leaves_a_membership_still_within_its_30_day_cycle_untouched(): void
    {
        $membership = $this->membership();
        $membership->forceFill(['cycle_started_at' => now()->subDays(10)])->save();

        $this->artisan('app:reset-membership-cycles')->assertExitCode(0);

        $fresh = $membership->fresh();
        $this->assertSame(500, $fresh->quota_remaining_sen);
    }

    public function test_ignores_an_expired_status_membership_even_if_its_cycle_elapsed(): void
    {
        $membership = $this->membership(['status' => 'expired']);
        $membership->forceFill(['cycle_started_at' => now()->subDays(31)])->save();

        $this->artisan('app:reset-membership-cycles')->assertExitCode(0);

        $this->assertSame(500, $membership->fresh()->quota_remaining_sen);
    }

    public function test_flips_an_active_membership_past_its_expiry_to_expired(): void
    {
        $membership = $this->membership();
        $membership->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('app:reset-membership-cycles')->assertExitCode(0);

        $this->assertSame('expired', $membership->fresh()->status->value);
    }

    public function test_does_not_refill_quota_for_a_membership_that_just_flipped_to_expired(): void
    {
        // Lapsed AND cycle elapsed — the expiry flip runs before the
        // quota refill (Q7), so an expired membership must never get a
        // pointless refill.
        $membership = $this->membership();
        $membership->forceFill(['expires_at' => now()->subDay(), 'cycle_started_at' => now()->subDays(31)])->save();

        $this->artisan('app:reset-membership-cycles')->assertExitCode(0);

        $fresh = $membership->fresh();
        $this->assertSame('expired', $fresh->status->value);
        $this->assertSame(500, $fresh->quota_remaining_sen);
    }
}
