<?php

namespace Tests\Feature\Models;

use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-061 decision 5 (PR-B): consumer-membership identity is per-brand —
 * `unique(affiliate_id, email)`, not `unique(email)`.
 */
class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private function make(int $affiliateId, string $email): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        return Membership::query()->create([
            'affiliate_id' => $affiliateId,
            'email' => $email,
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $plan->quota_sen,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_the_same_email_can_hold_a_membership_on_two_different_brands(): void
    {
        $brandA = $this->primaryAffiliate();
        $brandB = Affiliate::query()->create([
            'business_name' => 'Sister Brand', 'markup_pct' => 0, 'status' => 'active',
            'is_owned' => true, 'membership_enabled' => true,
        ]);

        $a = $this->make($brandA->id, 'shared@example.com');
        $b = $this->make($brandB->id, 'shared@example.com');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Membership::query()->where('email', 'shared@example.com')->count());
    }

    public function test_the_same_email_cannot_be_recorded_twice_on_one_brand(): void
    {
        $brand = $this->primaryAffiliate();
        $this->make($brand->id, 'dup@example.com');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->make($brand->id, 'dup@example.com');
    }

    public function test_affiliate_relation_resolves_the_owning_brand(): void
    {
        $brand = $this->primaryAffiliate();

        $membership = $this->make($brand->id, 'member@example.com');

        $this->assertTrue($membership->affiliate->is($brand));
    }
}
