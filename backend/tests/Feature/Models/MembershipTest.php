<?php

namespace Tests\Feature\Models;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Reseller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-061 decision 5 (PR-B): consumer-membership identity is per-brand —
 * `unique(reseller_id, email)`, not `unique(email)`.
 */
class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private function make(int $resellerId, string $email): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        return Membership::query()->create([
            'reseller_id' => $resellerId,
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
        $brandA = $this->primaryReseller();
        $brandB = Reseller::query()->create([
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
        $brand = $this->primaryReseller();
        $this->make($brand->id, 'dup@example.com');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->make($brand->id, 'dup@example.com');
    }

    public function test_reseller_relation_resolves_the_owning_brand(): void
    {
        $brand = $this->primaryReseller();

        $membership = $this->make($brand->id, 'member@example.com');

        $this->assertTrue($membership->reseller->is($brand));
    }
}
