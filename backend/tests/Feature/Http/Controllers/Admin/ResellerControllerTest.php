<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-072/073 PR-B: admin Reseller (prepaid-wallet) account management.
 */
class ResellerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->getJson('/api/resellers')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/resellers')->assertUnauthorized();
    }

    public function test_store_creates_reseller_and_opens_a_wallet_ledger_account(): void
    {
        $this->actAsSuperAdmin();
        $tier = ResellerTier::query()->create([
            'name' => 'Gold', 'markup_percent' => 8, 'is_active' => true, 'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/resellers', [
            'business_name' => 'Acme Reseller',
            'contact_name' => 'Ah Beng',
            'email' => 'beng@acme.test',
            'reseller_tier_id' => $tier->id,
        ])->assertCreated();

        $id = $response->json('id');
        $this->assertDatabaseHas('resellers', ['id' => $id, 'business_name' => 'Acme Reseller', 'is_active' => 1]);
        $this->assertDatabaseHas('ledger_accounts', [
            'owner_type' => LedgerOwnerType::ResellerWallet->value,
            'owner_id' => $id,
        ]);
        $response->assertJsonPath('wallet_balance_sen', 0)
            ->assertJsonPath('tier_name', 'Gold');
    }

    public function test_index_lists_resellers_with_wallet_balance(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $this->getJson('/api/resellers')->assertOk()
            ->assertJsonPath('resellers.0.business_name', 'Acme')
            ->assertJsonPath('resellers.0.wallet_balance_sen', 0);
    }

    public function test_assign_tier_swaps_the_fk_immediately(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        $tier = ResellerTier::query()->create([
            'name' => 'Silver', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->postJson("/api/resellers/{$reseller->id}/tier", ['reseller_tier_id' => $tier->id])
            ->assertOk()
            ->assertJsonPath('reseller_tier_id', $tier->id);

        $this->assertDatabaseHas('resellers', ['id' => $reseller->id, 'reseller_tier_id' => $tier->id]);
    }

    public function test_update_status_deactivates_without_touching_the_wallet(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'wallet_topup');

        $this->patchJson("/api/resellers/{$reseller->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('wallet_balance_sen', 5000);

        $this->assertDatabaseHas('resellers', ['id' => $reseller->id, 'is_active' => 0]);
    }

    public function test_destroy_soft_deletes_when_wallet_balance_is_zero(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $this->deleteJson("/api/resellers/{$reseller->id}")->assertOk();
        $this->assertSoftDeleted('resellers', ['id' => $reseller->id]);
    }

    public function test_destroy_blocked_when_wallet_balance_nonzero(): void
    {
        $this->actAsSuperAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 1000, 'wallet_topup');

        $this->deleteJson("/api/resellers/{$reseller->id}")->assertUnprocessable();
        $this->assertDatabaseHas('resellers', ['id' => $reseller->id, 'deleted_at' => null]);
    }
}
