<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Services\Reseller\ResellerSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-058 58b / ADR-056 decision 1: reseller_membership_tiers CRUD.
 */
class ResellerMembershipTierControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->getJson('/api/reseller-tiers')->assertForbidden();
    }

    public function test_crud_roundtrip(): void
    {
        $this->actAsSuperAdmin();

        $create = $this->postJson('/api/reseller-tiers', [
            'name' => 'Silver',
            'monthly_fee_sen' => 5000,
            'markup_percent' => 5,
        ])->assertCreated();

        $id = $create->json('id');

        $this->putJson("/api/reseller-tiers/{$id}", ['monthly_fee_sen' => 7000])->assertOk()
            ->assertJsonPath('monthly_fee_sen', 7000);

        $this->getJson('/api/reseller-tiers')->assertOk()->assertJsonPath('tiers.0.name', 'Silver');

        $this->deleteJson("/api/reseller-tiers/{$id}")->assertOk();
        $this->assertSoftDeleted('reseller_membership_tiers', ['id' => $id]);
    }

    public function test_delete_blocked_when_active_subscription_exists(): void
    {
        $this->actAsSuperAdmin();
        $tier = ResellerMembershipTier::query()->create([
            'name' => 'Gold', 'monthly_fee_sen' => 9000, 'markup_percent' => 8, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'status' => 'active',
        ]);
        app(ResellerSubscriptionService::class)->assignTier($reseller, $tier);

        $this->deleteJson("/api/reseller-tiers/{$tier->id}")->assertUnprocessable();
        $this->assertDatabaseHas('reseller_membership_tiers', ['id' => $tier->id, 'deleted_at' => null]);
    }

    public function test_delete_allowed_once_the_only_subscriber_is_soft_deleted(): void
    {
        $this->actAsSuperAdmin();
        $tier = ResellerMembershipTier::query()->create([
            'name' => 'Bronze', 'monthly_fee_sen' => 3000, 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Gone', 'markup_pct' => 10, 'status' => 'active',
        ]);
        app(ResellerSubscriptionService::class)->assignTier($reseller, $tier);

        $reseller->delete(); // soft-delete — the subscription row lingers

        $this->getJson('/api/reseller-tiers')->assertOk()->assertJsonPath('tiers.0.subscriptions_count', 0);
        $this->deleteJson("/api/reseller-tiers/{$tier->id}")->assertOk();
        $this->assertSoftDeleted('reseller_membership_tiers', ['id' => $tier->id]);
    }
}
