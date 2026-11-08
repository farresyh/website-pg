<?php

namespace Tests\Feature\Reseller;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-139 decision 7: a tier change takes effect on the 1st (KL), recorded as history; markup is fixed per tier. */
class ResellerTierChangeTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 15 Nov 2026, 10:00 KL.
        Carbon::setTestNow(Carbon::parse('2026-11-15 02:00:00', 'UTC'));
        $this->admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function tier(string $name, float $markup): ResellerTier
    {
        return ResellerTier::query()->create(['name' => $name, 'markup_percent' => $markup, 'is_active' => true, 'sort_order' => 1]);
    }

    private function reseller(?ResellerTier $tier): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true, 'reseller_tier_id' => $tier?->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    public function test_provisioning_records_the_first_tier_as_applied_today(): void
    {
        Http::fake();
        $sss = $this->tier('SSS', 2);

        $reseller = app(ResellerProvisioningService::class)->provision([
            'business_name' => 'Acme', 'contact_name' => 'Ali', 'email' => 'ali@example.com', 'reseller_tier_id' => $sss->id,
        ]);

        $change = ResellerWalletTierChange::query()->where('reseller_id', $reseller->id)->sole();
        $this->assertNull($change->from_tier_id);
        $this->assertSame($sss->id, $change->to_tier_id);
        $this->assertSame('2026-11-15', $change->effective_on->toDateString());
        $this->assertNotNull($change->applied_at);
    }

    public function test_a_change_waits_for_the_next_first_and_the_price_stays(): void
    {
        $ss = $this->tier('SS', 3);
        $sss = $this->tier('SSS', 2);
        $reseller = $this->reseller($ss);

        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $sss->id])
            ->assertOk()
            ->assertJsonPath('reseller_tier_id', $ss->id)
            ->assertJsonPath('pending_tier_change.to_tier_id', $sss->id)
            ->assertJsonPath('pending_tier_change.effective_on', '2026-12-01');

        $this->assertSame($ss->id, $reseller->fresh()->reseller_tier_id);
    }

    public function test_a_second_change_replaces_the_pending_one_and_the_current_tier_cancels_it(): void
    {
        $ss = $this->tier('SS', 3);
        $sss = $this->tier('SSS', 2);
        $a = $this->tier('A', 5);
        $reseller = $this->reseller($ss);

        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $sss->id])->assertOk();
        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $a->id])->assertOk();
        $this->assertSame([$a->id], ResellerWalletTierChange::query()->whereNull('applied_at')->pluck('to_tier_id')->all());

        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $ss->id])
            ->assertOk()
            ->assertJsonPath('pending_tier_change', null);
        $this->assertSame(0, ResellerWalletTierChange::query()->whereNull('applied_at')->count());
    }

    public function test_a_reseller_with_no_tier_gets_one_at_once(): void
    {
        $sss = $this->tier('SSS', 2);
        $reseller = $this->reseller(null);

        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $sss->id])
            ->assertOk()
            ->assertJsonPath('reseller_tier_id', $sss->id)
            ->assertJsonPath('pending_tier_change', null);

        $this->assertNotNull(ResellerWalletTierChange::query()->sole()->applied_at);
    }

    public function test_the_task_applies_a_change_at_midnight_kl_on_the_first_and_only_once(): void
    {
        $ss = $this->tier('SS', 3);
        $sss = $this->tier('SSS', 2);
        $reseller = $this->reseller($ss);
        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $sss->id])->assertOk();

        // 30 Nov 23:59 KL: not yet.
        Carbon::setTestNow(Carbon::parse('2026-11-30 15:59:00', 'UTC'));
        $this->artisan('app:apply-reseller-tier-changes')->assertSuccessful();
        $this->assertSame($ss->id, $reseller->fresh()->reseller_tier_id);

        // 1 Dec 00:00 KL.
        Carbon::setTestNow(Carbon::parse('2026-11-30 16:00:00', 'UTC'));
        $this->artisan('app:apply-reseller-tier-changes')->assertSuccessful();
        $this->artisan('app:apply-reseller-tier-changes')->assertSuccessful();

        $this->assertSame($sss->id, $reseller->fresh()->reseller_tier_id);
        $change = ResellerWalletTierChange::query()->where('to_tier_id', $sss->id)->sole();
        $this->assertSame($ss->id, $change->from_tier_id);
        $this->assertNotNull($change->applied_at);
    }

    public function test_a_tier_markup_cannot_be_edited_but_its_name_can(): void
    {
        $ss = $this->tier('SS', 3);

        $this->putJson("/api/reseller-tiers/{$ss->id}", ['markup_percent' => 1.5])->assertUnprocessable()->assertJsonValidationErrors('markup_percent');
        $this->putJson("/api/reseller-tiers/{$ss->id}", ['name' => 'Tier SS'])->assertOk()->assertJsonPath('name', 'Tier SS');

        $this->assertSame('3.00', $ss->fresh()->markup_percent);
    }

    public function test_a_tier_with_a_pending_change_into_it_cannot_be_deleted(): void
    {
        $ss = $this->tier('SS', 3);
        $vip = $this->tier('VIP', 1.5);
        $reseller = $this->reseller($ss);
        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $vip->id])->assertOk();

        $this->deleteJson("/api/reseller-tiers/{$vip->id}")->assertUnprocessable();
        $this->assertNotSoftDeleted($vip);
    }
}
