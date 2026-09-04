<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-072/073 PR-B: reseller_tiers CRUD.
 */
class ResellerTierControllerTest extends TestCase
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
            'markup_percent' => 5,
        ])->assertCreated();

        $id = $create->json('id');

        $this->putJson("/api/reseller-tiers/{$id}", ['markup_percent' => 7])->assertOk()
            ->assertJsonPath('markup_percent', '7.00');

        $this->getJson('/api/reseller-tiers')->assertOk()->assertJsonPath('tiers.0.name', 'Silver');

        $this->deleteJson("/api/reseller-tiers/{$id}")->assertOk();
        $this->assertSoftDeleted('reseller_tiers', ['id' => $id]);
    }

    public function test_delete_blocked_when_a_reseller_is_assigned(): void
    {
        $this->actAsSuperAdmin();
        $tier = ResellerTier::query()->create([
            'name' => 'Gold', 'markup_percent' => 8, 'is_active' => true, 'sort_order' => 1,
        ]);
        Reseller::query()->create([
            'business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);

        $this->deleteJson("/api/reseller-tiers/{$tier->id}")->assertUnprocessable();
        $this->assertDatabaseHas('reseller_tiers', ['id' => $tier->id, 'deleted_at' => null]);
    }

    public function test_delete_allowed_once_the_only_reseller_is_moved_off(): void
    {
        $this->actAsSuperAdmin();
        $tier = ResellerTier::query()->create([
            'name' => 'Bronze', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Gone', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);
        $reseller->update(['reseller_tier_id' => null]);

        $this->deleteJson("/api/reseller-tiers/{$tier->id}")->assertOk();
        $this->assertSoftDeleted('reseller_tiers', ['id' => $tier->id]);
    }
}
