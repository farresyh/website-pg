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

    /** ADR-091: at most 3 tiers may have show_on_price_list = true. */
    public function test_show_on_price_list_capped_at_three_on_create(): void
    {
        $this->actAsSuperAdmin();

        foreach (['SS', 'S', 'A'] as $i => $name) {
            $this->postJson('/api/reseller-tiers', [
                'name' => $name, 'markup_percent' => $i + 3, 'show_on_price_list' => true,
            ])->assertCreated();
        }

        $this->postJson('/api/reseller-tiers', [
            'name' => 'B', 'markup_percent' => 6, 'show_on_price_list' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('show_on_price_list');

        $this->assertSame(3, ResellerTier::query()->where('show_on_price_list', true)->count());
    }

    public function test_show_on_price_list_capped_at_three_on_update(): void
    {
        $this->actAsSuperAdmin();

        $tiers = collect(['SS', 'S', 'A'])->map(
            fn ($name, $i) => ResellerTier::query()->create([
                'name' => $name, 'markup_percent' => $i + 3, 'show_on_price_list' => true, 'is_active' => true,
            ]),
        );

        $notShown = ResellerTier::query()->create([
            'name' => 'SSS', 'markup_percent' => 0, 'show_on_price_list' => false, 'is_active' => true,
        ]);

        $this->putJson("/api/reseller-tiers/{$notShown->id}", ['show_on_price_list' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('show_on_price_list');

        // Re-saving an already-shown tier (still true) must not trip its own count.
        $this->putJson("/api/reseller-tiers/{$tiers->first()->id}", ['show_on_price_list' => true])
            ->assertOk();
    }
}
