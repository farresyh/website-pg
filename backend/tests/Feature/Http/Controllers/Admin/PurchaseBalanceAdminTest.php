<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-131 decisions 2, 4 and 11 from the admin side: the policy and the
 * per-reseller cap (both behind step-up), the seller details, and a
 * manual credit held to the same limits as a top-up.
 */
class PurchaseBalanceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    private function reseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    public function test_the_policy_is_saved_and_settings_return_it(): void
    {
        $this->putJson('/api/settings/purchase-balance-policy', ['topup_max_sen' => 500_000, 'balance_cap_default_sen' => 2_000_000])
            ->assertOk()
            ->assertJsonPath('topup_max_sen', 500_000)
            ->assertJsonPath('balance_cap_default_sen', 2_000_000);

        $this->assertSame(500_000, PlatformSettings::current()->topup_max_sen);
    }

    public function test_a_cap_below_the_per_top_up_maximum_is_refused(): void
    {
        $this->putJson('/api/settings/purchase-balance-policy', ['topup_max_sen' => 500_000, 'balance_cap_default_sen' => 400_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance_cap_default_sen');

        $this->assertSame(1_000_000, PlatformSettings::current()->topup_max_sen);
    }

    public function test_a_maximum_below_the_minimum_top_up_is_refused(): void
    {
        $this->putJson('/api/settings/purchase-balance-policy', ['topup_max_sen' => 500, 'balance_cap_default_sen' => 2_000_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('topup_max_sen');
    }

    public function test_the_seller_details_save_through_the_platform_settings_without_resetting_the_rest(): void
    {
        $this->putJson('/api/settings/platform', [
            'maintenance_mode' => false, 'vip_spend_threshold_sen' => 500_000,
            'company_legal_name' => 'LWF Group Sdn. Bhd.', 'company_reg_no' => '202501000001', 'company_address' => '1 Jalan Test',
        ])->assertOk()->assertJsonPath('company_reg_no', '202501000001');

        // A client that predates the fields must not blank them.
        $this->putJson('/api/settings/platform', ['maintenance_mode' => false, 'vip_spend_threshold_sen' => 500_000])->assertOk();

        $this->assertSame('LWF Group Sdn. Bhd.', PlatformSettings::current()->company_legal_name);
    }

    public function test_a_reseller_cap_can_be_set_and_cleared_and_the_list_shows_the_effective_cap(): void
    {
        $reseller = $this->reseller();

        $this->putJson("/api/resellers/{$reseller->id}/balance-cap", ['balance_cap_sen' => 50_000_000])
            ->assertOk()
            ->assertJsonPath('balance_cap_sen', 50_000_000)
            ->assertJsonPath('effective_balance_cap_sen', 50_000_000);

        $this->putJson("/api/resellers/{$reseller->id}/balance-cap", ['balance_cap_sen' => null])
            ->assertOk()
            ->assertJsonPath('balance_cap_sen', null)
            ->assertJsonPath('effective_balance_cap_sen', 10_000_000);

        $this->getJson('/api/resellers')->assertOk()->assertJsonPath('resellers.0.effective_balance_cap_sen', 10_000_000);
    }

    public function test_a_missing_cap_field_is_a_422_not_a_silent_reset(): void
    {
        $reseller = $this->reseller();
        $reseller->update(['balance_cap_sen' => 50_000_000]);

        $this->putJson("/api/resellers/{$reseller->id}/balance-cap", [])->assertUnprocessable();

        $this->assertSame(50_000_000, $reseller->fresh()->balance_cap_sen);
    }

    public function test_the_generic_reseller_update_never_touches_the_cap(): void
    {
        $reseller = $this->reseller();
        $reseller->update(['balance_cap_sen' => 50_000_000]);

        $this->putJson("/api/resellers/{$reseller->id}", ['business_name' => 'Acme Renamed', 'balance_cap_sen' => 1])->assertOk();

        $this->assertSame(50_000_000, $reseller->fresh()->balance_cap_sen);
    }

    public function test_a_manual_credit_over_the_maximum_is_a_422_and_writes_nothing(): void
    {
        $reseller = $this->reseller();

        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", ['amount_sen' => 1_000_001, 'idempotency_key' => 'key-over-max'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_sen');

        $this->assertSame(0, LedgerEntry::query()->where('type', 'wallet_topup')->count());
    }
}
