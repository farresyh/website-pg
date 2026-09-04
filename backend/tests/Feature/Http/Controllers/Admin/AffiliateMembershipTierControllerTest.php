<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Services\Affiliate\AffiliateSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-058 58b / ADR-056 decision 1: affiliate_membership_tiers CRUD.
 */
class AffiliateMembershipTierControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->getJson('/api/affiliate-tiers')->assertForbidden();
    }

    public function test_crud_roundtrip(): void
    {
        $this->actAsSuperAdmin();

        $create = $this->postJson('/api/affiliate-tiers', [
            'name' => 'Silver',
            'monthly_fee_sen' => 5000,
            'markup_percent' => 5,
        ])->assertCreated();

        $id = $create->json('id');

        $this->putJson("/api/affiliate-tiers/{$id}", ['monthly_fee_sen' => 7000])->assertOk()
            ->assertJsonPath('monthly_fee_sen', 7000);

        $this->getJson('/api/affiliate-tiers')->assertOk()->assertJsonPath('tiers.0.name', 'Silver');

        $this->deleteJson("/api/affiliate-tiers/{$id}")->assertOk();
        $this->assertSoftDeleted('affiliate_membership_tiers', ['id' => $id]);
    }

    public function test_delete_blocked_when_active_subscription_exists(): void
    {
        $this->actAsSuperAdmin();
        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Gold', 'monthly_fee_sen' => 9000, 'markup_percent' => 8, 'is_active' => true, 'sort_order' => 1,
        ]);
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'status' => 'active',
        ]);
        app(AffiliateSubscriptionService::class)->assignTier($affiliate, $tier);

        $this->deleteJson("/api/affiliate-tiers/{$tier->id}")->assertUnprocessable();
        $this->assertDatabaseHas('affiliate_membership_tiers', ['id' => $tier->id, 'deleted_at' => null]);
    }

    public function test_delete_allowed_once_the_only_subscriber_is_soft_deleted(): void
    {
        $this->actAsSuperAdmin();
        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Bronze', 'monthly_fee_sen' => 3000, 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1,
        ]);
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Gone', 'markup_pct' => 10, 'status' => 'active',
        ]);
        app(AffiliateSubscriptionService::class)->assignTier($affiliate, $tier);

        $affiliate->delete(); // soft-delete — the subscription row lingers

        $this->getJson('/api/affiliate-tiers')->assertOk()->assertJsonPath('tiers.0.subscriptions_count', 0);
        $this->deleteJson("/api/affiliate-tiers/{$tier->id}")->assertOk();
        $this->assertSoftDeleted('affiliate_membership_tiers', ['id' => $tier->id]);
    }
}
