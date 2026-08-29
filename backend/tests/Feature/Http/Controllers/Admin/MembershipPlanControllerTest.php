<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Supplier;
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

    private function package(Game $game, array $overrides = []): Package
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV' . random_int(100000, 999999),
            'name' => '100 Diamonds',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
        ], $overrides));
    }

    public function test_preview_returns_empty_array_when_no_active_packages_exist(): void
    {
        $this->actingAsSuperAdmin();

        $this->getJson('/api/membership-plans/preview?discount_percent=50')
            ->assertOk()
            ->assertJson([]);
    }

    /**
     * Reuses the exact worked example already proven at the unit level
     * (MembershipPricingServiceTest) — this test's real job is proving
     * the preview endpoint wires real package data through that same
     * formula correctly, not re-deriving the math.
     */
    public function test_preview_computes_member_price_and_margin_forgone_for_sample_packages(): void
    {
        $this->actingAsSuperAdmin();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);
        $this->package($game, ['cost_price' => 1000, 'reseller_cost_price' => 1150, 'markup_percent' => 15]);

        $response = $this->getJson('/api/membership-plans/preview?discount_percent=80')->assertOk();

        $row = $response->json()[0];
        // Normal: Platform Owner markup_pct=0 (ADR-013) -> selling_price = reseller_cost_price = 1150.
        $this->assertSame(1150, $row['normal_price_sen']);
        // Member: effective markup 15% * (1-0.8) = 3% -> round(1000 * 1.03) = 1030.
        $this->assertSame(1030, $row['member_price_sen']);
        $this->assertSame(120, $row['margin_forgone_sen']);
    }

    public function test_preview_samples_cheapest_median_and_priciest_active_packages_only(): void
    {
        $this->actingAsSuperAdmin();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);
        $this->package($game, ['name' => 'Cheapest', 'cost_price' => 100, 'reseller_cost_price' => 115]);
        $this->package($game, ['name' => 'Middle', 'cost_price' => 500, 'reseller_cost_price' => 575]);
        $this->package($game, ['name' => 'Priciest', 'cost_price' => 5000, 'reseller_cost_price' => 5750]);
        $this->package($game, ['name' => 'Inactive', 'cost_price' => 1, 'reseller_cost_price' => 1, 'is_active' => false]);

        $response = $this->getJson('/api/membership-plans/preview?discount_percent=50')->assertOk();

        $this->assertSame(['Cheapest', 'Middle', 'Priciest'], collect($response->json())->pluck('package_name')->all());
    }

    public function test_preview_rejects_a_regular_admin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/membership-plans/preview?discount_percent=50')->assertForbidden();
    }
}
