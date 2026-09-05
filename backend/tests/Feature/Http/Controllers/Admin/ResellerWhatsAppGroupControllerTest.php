<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** PR-F build addendum decision 3: admin's WhatsApp-group linking screen. */
class ResellerWhatsAppGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeReseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/reseller-whatsapp-groups/pending')->assertForbidden();
    }

    public function test_pending_lists_unmatched_groups_platform_wide(): void
    {
        $this->actAsSuperAdmin();
        ResellerWhatsAppPendingLink::query()->create(['whatsapp_group_id' => 'g1@g.us', 'last_message_preview' => 'hi', 'last_message_at' => now()]);

        $response = $this->getJson('/api/reseller-whatsapp-groups/pending');

        $response->assertOk();
        $response->assertJsonPath('0.whatsapp_group_id', 'g1@g.us');
    }

    public function test_store_links_a_group_and_clears_its_pending_row(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        ResellerWhatsAppPendingLink::query()->create(['whatsapp_group_id' => 'g1@g.us', 'last_message_at' => now()]);

        $response = $this->postJson("/api/resellers/{$reseller->id}/whatsapp-groups", ['whatsapp_group_id' => 'g1@g.us']);

        $response->assertCreated();
        $this->assertDatabaseHas('reseller_whatsapp_groups', ['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => 1]);
        $this->assertDatabaseMissing('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'g1@g.us']);
    }

    public function test_update_status_unlinks_a_group(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $group = ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        $response = $this->patchJson("/api/resellers/{$reseller->id}/whatsapp-groups/{$group->id}/status", ['is_active' => false]);

        $response->assertOk();
        $this->assertFalse($group->fresh()->is_active);
    }

    public function test_update_status_404s_for_a_group_belonging_to_a_different_reseller(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $otherReseller = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        $group = ResellerWhatsAppGroup::query()->create(['reseller_id' => $otherReseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        $response = $this->patchJson("/api/resellers/{$reseller->id}/whatsapp-groups/{$group->id}/status", ['is_active' => false]);

        $response->assertNotFound();
    }

    public function test_index_lists_a_resellers_own_groups(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        $response = $this->getJson("/api/resellers/{$reseller->id}/whatsapp-groups");

        $response->assertOk();
        $response->assertJsonPath('0.whatsapp_group_id', 'g1@g.us');
    }
}
