<?php

namespace Tests\Feature\Campaign;

use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 V15/V17/V19, PR 4: the admin Campaigns page's API. Super admin
 * only. A campaign is born with its fee row; a code's string is unique
 * forever and its percentage is fixed once it has been redeemed.
 */
class CampaignAdminTest extends TestCase
{
    use PromoCodeFixtures;
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 04:00:00');
        $this->primaryAffiliate();
        $this->admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_a_campaign_is_created_with_its_fee_on_the_primary_brand(): void
    {
        $id = $this->postJson('/api/campaigns', ['name' => 'KOL 1', 'monthly_fee_sen' => 50_000, 'from_month' => '2026-11'])
            ->assertCreated()
            ->json('id');

        $campaign = Campaign::query()->with('fees')->findOrFail($id);
        $this->assertSame($this->primaryAffiliate()->id, $campaign->affiliate_id);
        $this->assertSame(50_000, $campaign->fees->first()->monthly_fee_sen);

        $this->postJson('/api/campaigns', ['name' => 'No fee'])->assertJsonValidationErrors(['monthly_fee_sen', 'from_month']);
    }

    public function test_only_a_super_admin_manages_campaigns(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/campaigns')->assertForbidden();
        $this->postJson('/api/campaigns', ['name' => 'KOL 1', 'monthly_fee_sen' => 0, 'from_month' => '2026-11'])->assertForbidden();
    }

    public function test_a_code_is_stored_uppercase_and_is_unique_forever_across_campaigns(): void
    {
        $campaign = $this->campaign();

        $this->postJson("/api/campaigns/{$campaign->id}/codes", ['code' => ' kol5 ', 'discount_percent' => 5, 'budget_sen' => 10_000])
            ->assertCreated()
            ->assertJsonPath('code', 'KOL5');

        $other = $this->campaign('KOL 2');
        $this->postJson("/api/campaigns/{$other->id}/codes", ['code' => 'Kol5', 'discount_percent' => 5, 'budget_sen' => 10_000])
            ->assertJsonValidationErrors('code');
    }

    public function test_a_code_must_be_3_to_20_letters_or_digits(): void
    {
        $campaign = $this->campaign();

        foreach (['AB', str_repeat('A', 21), 'VC-ABC123', 'KOL 5', 'KOL_5'] as $bad) {
            $this->postJson("/api/campaigns/{$campaign->id}/codes", ['code' => $bad, 'discount_percent' => 5, 'budget_sen' => 10_000])
                ->assertJsonValidationErrors('code');
        }

        $this->postJson("/api/campaigns/{$campaign->id}/codes", ['code' => 'KOL5', 'discount_percent' => 0, 'budget_sen' => 10_000])
            ->assertJsonValidationErrors('discount_percent');
        $this->postJson("/api/campaigns/{$campaign->id}/codes", ['code' => 'KOL5', 'discount_percent' => 5, 'budget_sen' => 0])
            ->assertJsonValidationErrors('budget_sen');
    }

    public function test_the_percentage_is_fixed_after_the_first_redemption_but_budget_and_max_stay_editable(): void
    {
        $code = $this->promoCode('KOL5', ['campaign_id' => $this->campaign()->id]);

        $this->patchJson("/api/promo-codes/{$code->id}", ['discount_percent' => 7])->assertOk();
        $this->assertSame('7.00', $code->fresh()->discount_percent);

        $order = $this->earlierOrder(Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'is_active' => true]));
        PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'order_id' => $order->id, 'amount_sen' => 25, 'status' => PromoCodeRedemption::STATUS_RESTORED, 'restored_sen' => 25]);

        $this->patchJson("/api/promo-codes/{$code->id}", ['discount_percent' => 9])->assertJsonValidationErrors('discount_percent');
        $this->patchJson("/api/promo-codes/{$code->id}", ['budget_sen' => 20_000, 'max_discount_sen' => 500, 'is_active' => false])->assertOk();

        $code->refresh();
        $this->assertSame('7.00', $code->discount_percent);
        $this->assertSame(20_000, $code->budget_sen);
        $this->assertSame(500, $code->max_discount_sen);
        $this->assertFalse($code->is_active);
    }

    public function test_ending_a_campaign_stops_its_codes(): void
    {
        $campaign = $this->campaign();
        $code = PromoCode::query()->create(['campaign_id' => $campaign->id, 'code' => 'KOL5', 'discount_percent' => 5, 'budget_sen' => 10_000, 'is_active' => true]);
        $this->assertTrue($code->isRedeemableNow());

        $this->patchJson("/api/campaigns/{$campaign->id}", ['ended' => true, 'last_fee_month' => now('Asia/Kuala_Lumpur')->format('Y-m')])->assertOk();

        $this->assertFalse($code->fresh()->isRedeemableNow());
    }

    public function test_a_renegotiated_fee_is_a_new_row_and_a_row_can_be_given_an_end_month(): void
    {
        $campaign = $this->campaign();

        $this->postJson("/api/campaigns/{$campaign->id}/fees", ['monthly_fee_sen' => 80_000, 'from_month' => '2027-01'])->assertCreated();
        $first = $campaign->fees()->first();
        $this->patchJson("/api/campaign-fees/{$first->id}", ['to_month' => '2026-12'])->assertOk();

        $this->getJson("/api/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonCount(2, 'fees')
            ->assertJsonPath('fees.0.to_month', '2026-12')
            ->assertJsonPath('fees.1.monthly_fee_sen', 80_000);
    }

    public function test_the_margin_check_lists_the_packages_a_percentage_would_refuse(): void
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'S', 'slug' => 's', 'api_config' => [], 'currency' => 'MYR']);
        // cost 421, price 500: 5% leaves 54 sen (11%), 15% leaves 4 sen (0.9%, under the 2% floor).
        Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500, 'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);

        $this->getJson('/api/campaigns/margin-check?discount_percent=5')->assertOk()->assertJsonCount(0, 'rejected');
        $this->getJson('/api/campaigns/margin-check?discount_percent=15')
            ->assertOk()
            ->assertJsonCount(1, 'rejected')
            ->assertJsonPath('rejected.0.package_name', '100 Diamonds');
        // A per-order cap of RM0.20 keeps 15% above the floor.
        $this->getJson('/api/campaigns/margin-check?discount_percent=15&max_discount_sen=20')->assertJsonCount(0, 'rejected');
    }

    public function test_the_campaign_settings_are_editable_and_target_and_trial_can_be_empty(): void
    {
        $this->patchJson('/api/campaigns/settings', [
            'campaign_min_margin_percent' => 3,
            'campaign_qualified_min_sen' => 1500,
            'campaign_target_cac_sen' => 2000,
            'campaign_trial_months' => 3,
        ])->assertOk();

        $settings = PlatformSettings::current()->fresh();
        $this->assertSame('3.00', $settings->campaign_min_margin_percent);
        $this->assertSame(1500, $settings->campaign_qualified_min_sen);
        $this->assertSame(2000, $settings->campaign_target_cac_sen);
        $this->assertSame(3, $settings->campaign_trial_months);

        $this->patchJson('/api/campaigns/settings', ['campaign_target_cac_sen' => null, 'campaign_trial_months' => null])->assertOk();
        $this->assertNull(PlatformSettings::current()->fresh()->campaign_target_cac_sen);
    }

    private function campaign(string $name = 'KOL 1'): Campaign
    {
        return Campaign::query()->findOrFail(
            $this->postJson('/api/campaigns', ['name' => $name, 'monthly_fee_sen' => 50_000, 'from_month' => '2026-11'])->assertCreated()->json('id'),
        );
    }
}
