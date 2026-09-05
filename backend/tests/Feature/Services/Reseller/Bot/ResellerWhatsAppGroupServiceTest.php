<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use App\Services\Reseller\Bot\ResellerWhatsAppGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-075 decision 2 / PR-F build addendum decision 3. */
class ResellerWhatsAppGroupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResellerWhatsAppGroupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ResellerWhatsAppGroupService;
    }

    private function reseller(): Reseller
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 10, 'is_active' => true, 'sort_order' => 1]);

        return Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
    }

    public function test_resolves_reseller_for_an_active_linked_group(): void
    {
        $reseller = $this->reseller();
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        $resolved = $this->service->resolveReseller('g1@g.us');

        $this->assertNotNull($resolved);
        $this->assertSame($reseller->id, $resolved->id);
    }

    public function test_returns_null_for_an_inactive_linked_group(): void
    {
        $reseller = $this->reseller();
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => false]);

        $this->assertNull($this->service->resolveReseller('g1@g.us'));
    }

    public function test_returns_null_for_an_unmapped_group(): void
    {
        $this->assertNull($this->service->resolveReseller('unknown@g.us'));
    }

    public function test_capture_pending_creates_a_row_and_bumps_it_on_repeat(): void
    {
        $this->service->capturePending('g1@g.us', 'hello');
        $first = ResellerWhatsAppPendingLink::query()->where('whatsapp_group_id', 'g1@g.us')->first();

        $this->travel(5)->minutes();
        $this->service->capturePending('g1@g.us', 'second message');

        $this->assertSame(1, ResellerWhatsAppPendingLink::query()->count());
        $updated = ResellerWhatsAppPendingLink::query()->where('whatsapp_group_id', 'g1@g.us')->first();
        $this->assertSame('second message', $updated->last_message_preview);
        $this->assertTrue($updated->last_message_at->gt($first->last_message_at));
    }

    public function test_link_creates_the_mapping_and_clears_any_pending_row(): void
    {
        $reseller = $this->reseller();
        $this->service->capturePending('g1@g.us', 'hi');

        $group = $this->service->link($reseller, 'g1@g.us');

        $this->assertSame($reseller->id, $group->reseller_id);
        $this->assertTrue($group->is_active);
        $this->assertSame(0, ResellerWhatsAppPendingLink::query()->count());
    }

    public function test_unlink_deactivates_without_deleting(): void
    {
        $reseller = $this->reseller();
        $group = ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        $this->service->unlink($group);

        $this->assertFalse($group->fresh()->is_active);
        $this->assertSame(1, ResellerWhatsAppGroup::query()->count());
    }

    public function test_reactivate_flips_it_back_on(): void
    {
        $reseller = $this->reseller();
        $group = ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => false]);

        $this->service->reactivate($group);

        $this->assertTrue($group->fresh()->is_active);
    }

    public function test_prune_expired_pending_links_deletes_only_stale_rows(): void
    {
        ResellerWhatsAppPendingLink::query()->create(['whatsapp_group_id' => 'stale@g.us', 'last_message_at' => now()->subHours(25)]);
        ResellerWhatsAppPendingLink::query()->create(['whatsapp_group_id' => 'fresh@g.us', 'last_message_at' => now()->subHours(1)]);

        $deleted = $this->service->pruneExpiredPendingLinks(24);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'fresh@g.us']);
        $this->assertDatabaseMissing('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'stale@g.us']);
    }
}
