<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29): the admin member registry + record-payment endpoints.
 * `super_admin` only, same tier as membership-plans.
 *
 * ADR-061 decision 5 (PR-B): a membership belongs to one brand — every
 * record-payment call names the `reseller_id`, and the registry can be
 * filtered by it.
 */
class MembershipControllerTest extends TestCase
{
    use RefreshDatabase;

    private Reseller $brand;

    protected function setUp(): void
    {
        parent::setUp();
        // The primary reseller is internal + membership-enabled (the
        // helper's defaults), so it is a valid record-payment target.
        $this->brand = $this->primaryReseller();
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function tier1(): MembershipPlan
    {
        return MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
    }

    public function test_unauthenticated_is_blocked(): void
    {
        $this->getJson('/api/memberships')->assertUnauthorized();
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/memberships')->assertForbidden();
    }

    public function test_index_lists_members_with_effective_status_and_derived_quota(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();

        Membership::query()->create([
            'reseller_id' => $this->brand->id,
            'email' => 'active@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 4000,
            'expires_at' => now()->addDays(10),
        ]);

        // Lapsed but status not yet flipped by the sweep — must read expired (Q15).
        Membership::query()->create([
            'reseller_id' => $this->brand->id,
            'email' => 'lapsed@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now()->subDays(40),
            'quota_remaining_sen' => 0,
            'expires_at' => now()->subDays(2),
        ]);

        $response = $this->getJson('/api/memberships')->assertOk();

        $rows = collect($response->json('data'))->keyBy('email');

        $this->assertSame('active', $rows['active@example.com']['status']);
        $this->assertSame(6000, $rows['active@example.com']['quota_used_sen']); // 10000 - 4000
        $this->assertSame('expired', $rows['lapsed@example.com']['status']);
        $this->assertSame($plan->name, $rows['active@example.com']['plan_name']);
        $this->assertSame($this->brand->business_name, $rows['active@example.com']['brand_name']);
    }

    public function test_index_can_be_filtered_by_brand(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();
        $otherBrand = Reseller::query()->create([
            'business_name' => 'Sister Brand',
            'markup_pct' => 0,
            'status' => 'active',
            'is_owned' => true,
            'membership_enabled' => true,
        ]);

        Membership::query()->create([
            'reseller_id' => $this->brand->id, 'email' => 'a@example.com', 'membership_plan_id' => $plan->id,
            'status' => 'active', 'cycle_started_at' => now(), 'quota_remaining_sen' => 1, 'expires_at' => now()->addDay(),
        ]);
        Membership::query()->create([
            'reseller_id' => $otherBrand->id, 'email' => 'b@example.com', 'membership_plan_id' => $plan->id,
            'status' => 'active', 'cycle_started_at' => now(), 'quota_remaining_sen' => 1, 'expires_at' => now()->addDay(),
        ]);

        $rows = $this->getJson("/api/memberships?reseller_id={$otherBrand->id}")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('b@example.com', $rows[0]['email']);
    }

    public function test_brands_lists_only_internal_membership_enabled_resellers(): void
    {
        $this->actingAsSuperAdmin();
        Reseller::query()->create(['business_name' => 'Third-party', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false, 'membership_enabled' => false]);
        Reseller::query()->create(['business_name' => 'Internal, membership off', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true, 'membership_enabled' => false]);

        $brands = $this->getJson('/api/memberships/brands')->assertOk()->json();

        $this->assertSame([$this->brand->id], collect($brands)->pluck('id')->all());
    }

    public function test_record_payment_creates_a_membership(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();

        $this->postJson('/api/memberships/record-payment', [
            'reseller_id' => $this->brand->id,
            'email' => 'new@example.com',
            'membership_plan_id' => $plan->id,
            'amount_sen' => $plan->fee_sen,
            'idempotency_key' => 'key-record-1',
        ])->assertOk()->assertJson([
            'reseller_id' => $this->brand->id,
            'email' => 'new@example.com',
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('memberships', [
            'reseller_id' => $this->brand->id,
            'email' => 'new@example.com',
        ]);
    }

    public function test_record_payment_rejects_a_brand_without_membership(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();
        $thirdParty = Reseller::query()->create(['business_name' => 'Reseller X', 'markup_pct' => 8, 'status' => 'active', 'is_owned' => false]);

        $this->postJson('/api/memberships/record-payment', [
            'reseller_id' => $thirdParty->id,
            'email' => 'new@example.com',
            'membership_plan_id' => $plan->id,
            'amount_sen' => $plan->fee_sen,
            'idempotency_key' => 'key-record-x',
        ])->assertUnprocessable()->assertJsonValidationErrors('reseller_id');
    }

    public function test_record_payment_requires_reason_when_amount_deviates_from_plan_fee(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();

        $this->postJson('/api/memberships/record-payment', [
            'reseller_id' => $this->brand->id,
            'email' => 'promo@example.com',
            'membership_plan_id' => $plan->id,
            'amount_sen' => $plan->fee_sen + 100, // deviates, no reason
            'idempotency_key' => 'key-record-2',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_record_payment_accepts_deviation_with_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->tier1();

        $this->postJson('/api/memberships/record-payment', [
            'reseller_id' => $this->brand->id,
            'email' => 'promo@example.com',
            'membership_plan_id' => $plan->id,
            'amount_sen' => 500,
            'reason' => 'promo discount',
            'idempotency_key' => 'key-record-3',
        ])->assertOk();
    }
}
