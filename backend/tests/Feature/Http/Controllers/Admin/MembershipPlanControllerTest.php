<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\MembershipPlan;
use App\Models\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decisions 14/15/19/22:
 * /admin/membership's backend — edit-only against the two fixed
 * membership_plans rows seeded by the migration itself, cross-tier
 * discount validation, and the membership_plan_changes audit trail.
 */
class MembershipPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_unauthenticated_is_blocked(): void
    {
        $this->getJson('/api/membership-plans')->assertUnauthorized();
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/membership-plans')->assertForbidden();
    }

    public function test_index_returns_the_two_seeded_tiers_in_id_order(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->getJson('/api/membership-plans')->assertOk();

        $response->assertJsonCount(2);
        $this->assertSame('Tier 1', $response->json('0.name'));
        $this->assertSame('Tier 2', $response->json('1.name'));
    }

    public function test_updates_a_tier_when_discount_stays_below_the_higher_tier(): void
    {
        $this->actingAsSuperAdmin();
        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        $this->putJson("/api/membership-plans/{$tier1->id}", [
            'fee_sen' => 990,
            'quota_sen' => 12000,
            'discount_percent' => 55,
        ])->assertOk();

        $this->assertSame(990, $tier1->fresh()->fee_sen);
        $this->assertSame('55.00', $tier1->fresh()->discount_percent);
    }

    public function test_rejects_lower_tier_discount_at_or_above_higher_tier_discount(): void
    {
        $this->actingAsSuperAdmin();
        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        $tier2 = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        $this->putJson("/api/membership-plans/{$tier1->id}", [
            'fee_sen' => 990,
            'quota_sen' => 12000,
            'discount_percent' => (float) $tier2->discount_percent,
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_percent');
    }

    public function test_rejects_higher_tier_discount_at_or_below_lower_tier_discount(): void
    {
        $this->actingAsSuperAdmin();
        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        $tier2 = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        $this->putJson("/api/membership-plans/{$tier2->id}", [
            'fee_sen' => 1990,
            'quota_sen' => 30000,
            'discount_percent' => (float) $tier1->discount_percent,
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_percent');
    }

    public function test_logs_one_audit_row_per_changed_field_only(): void
    {
        $this->actingAsSuperAdmin();
        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        $originalQuota = $tier1->quota_sen;

        $this->putJson("/api/membership-plans/{$tier1->id}", [
            'fee_sen' => 999,
            'quota_sen' => $originalQuota,
            'discount_percent' => 55,
        ])->assertOk();

        $this->assertDatabaseCount('membership_plan_changes', 2);
        $this->assertDatabaseHas('membership_plan_changes', [
            'membership_plan_id' => $tier1->id,
            'field_changed' => 'fee_sen',
            'new_value' => '999',
        ]);
        $this->assertDatabaseMissing('membership_plan_changes', [
            'membership_plan_id' => $tier1->id,
            'field_changed' => 'quota_sen',
        ]);
    }

    public function test_saving_unchanged_values_writes_no_audit_rows(): void
    {
        $this->actingAsSuperAdmin();
        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        $this->putJson("/api/membership-plans/{$tier1->id}", [
            'fee_sen' => $tier1->fee_sen,
            'quota_sen' => $tier1->quota_sen,
            'discount_percent' => (float) $tier1->discount_percent,
        ])->assertOk();

        $this->assertDatabaseCount('membership_plan_changes', 0);
    }

    public function test_kill_switch_defaults_off_and_can_be_toggled(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertFalse(PlatformSettings::current()->membership_enabled);

        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])
            ->assertOk()
            ->assertJson(['membership_enabled' => true]);

        $this->assertTrue(PlatformSettings::current()->fresh()->membership_enabled);
    }
}
