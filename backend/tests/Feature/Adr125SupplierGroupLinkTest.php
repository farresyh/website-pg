<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppPendingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-125 decisions 55/56/60: Admin → Partners → Suppliers. A group has one
 * owner and never moves silently, a supplier has one active group, and an
 * active manual supplier always has one (both activation paths).
 */
class Adr125SupplierGroupLinkTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $manual;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $this->manual = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR', 'is_active' => false,
        ]);
    }

    public function test_link_then_activate_then_list(): void
    {
        WhatsAppPendingLink::query()->create(['whatsapp_group_id' => 'g1@g.us', 'last_message_at' => now()]);
        $this->getJson('/api/whatsapp-groups/pending')->assertOk()->assertJsonPath('0.whatsapp_group_id', 'g1@g.us');

        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/status", ['is_active' => true])->assertJsonValidationErrors('is_active');

        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g1@g.us'])
            ->assertCreated()->assertJsonPath('whatsapp_groups.0.is_active', true);
        $this->assertSame(0, WhatsAppPendingLink::query()->count());

        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/status", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/sla", ['reply_sla_minutes' => 45])->assertOk()->assertJsonPath('reply_sla_minutes', 45);

        $this->getJson('/api/partner-suppliers')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Roblox A')
            ->assertJsonPath('0.pending_orders', 0)
            ->assertJsonPath('0.whatsapp_groups.0.whatsapp_group_id', 'g1@g.us');
    }

    public function test_a_group_owned_by_a_reseller_never_moves_to_a_supplier(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => false]);

        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g1@g.us'])->assertJsonValidationErrors('whatsapp_group_id');
        $this->postJson("/api/resellers/{$reseller->id}/whatsapp-groups", ['whatsapp_group_id' => 'g1@g.us'])->assertCreated();

        $this->assertSame('reseller', WhatsAppGroup::query()->sole()->owner_type);
    }

    public function test_a_supplier_has_one_active_group(): void
    {
        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g1@g.us'])->assertCreated();
        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g2@g.us'])->assertJsonValidationErrors('whatsapp_group_id');

        $first = WhatsAppGroup::query()->sole();
        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group/{$first->id}/status", ['is_active' => false])->assertOk();
        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g2@g.us'])->assertCreated();
        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group/{$first->id}/status", ['is_active' => true])->assertJsonValidationErrors('whatsapp_group_id');
    }

    public function test_an_active_suppliers_group_cannot_be_unlinked(): void
    {
        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => 'g1@g.us'])->assertCreated();
        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/status", ['is_active' => true])->assertOk();
        $group = WhatsAppGroup::query()->sole();

        $this->patchJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group/{$group->id}/status", ['is_active' => false])->assertJsonValidationErrors('whatsapp_group_id');
        $this->assertTrue($group->fresh()->is_active);

        $other = Supplier::query()->create(['name' => 'B', 'slug' => 'roblox-b', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR']);
        $this->patchJson("/api/partner-suppliers/{$other->id}/whatsapp-group/{$group->id}/status", ['is_active' => false])->assertNotFound();
    }

    public function test_middleware_creates_a_manual_supplier_inactive_and_guards_its_activation(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $id = $this->postJson('/api/middleware/suppliers', ['driver' => 'manual', 'name' => 'Roblox C', 'slug' => 'roblox-c', 'currency' => 'MYR', 'funding_mode' => 'prepaid'])
            ->assertCreated()->json('id');
        $this->assertFalse(Supplier::query()->find($id)->is_active);

        $this->patchJson("/api/middleware/suppliers/{$id}/status", ['is_active' => true])->assertJsonValidationErrors('is_active');
    }

    public function test_an_api_supplier_never_takes_a_group_and_is_not_listed(): void
    {
        $api = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'driver' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);

        $this->postJson("/api/partner-suppliers/{$api->id}/whatsapp-group", ['whatsapp_group_id' => 'g1@g.us'])->assertJsonValidationErrors('supplier');
        $this->getJson('/api/partner-suppliers')->assertJsonCount(1);
    }

    public function test_an_admin_who_is_not_super_admin_is_refused(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/partner-suppliers')->assertForbidden();
        $this->getJson('/api/whatsapp-groups/pending')->assertForbidden();
    }
}
