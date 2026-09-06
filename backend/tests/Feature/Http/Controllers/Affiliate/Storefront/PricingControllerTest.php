<?php

namespace Tests\Feature\Http\Controllers\Affiliate\Storefront;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\AffiliateUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ADR-060 PR-6 — the Pricing tab: markup write (hard-capped + audited) + live preview. */
class PricingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'email' => 'owner+'.uniqid().'@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 25,
            'status' => 'active',
        ], $attributes));
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.uniqid().'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function package(int $cost, int $selling): Package
    {
        $game = Game::query()->create(['name' => 'ML '.uniqid(), 'slug' => 'ml-'.uniqid(), 'is_active' => true]);
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        return Package::query()->create([
            'game_id' => $game->id,
            'name' => 'Pack',
            'cost_price' => $cost,
            'standard_selling_price' => $selling,
            'markup_percent' => 0,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'ref-'.uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_update_writes_the_markup_and_an_audit_row(): void
    {
        $affiliate = $this->affiliate(['markup_pct' => 10]);

        $this->withToken($this->tokenFor($affiliate))
            ->putJson('/api/affiliate/storefront/pricing', ['markup_pct' => 15])
            ->assertOk()
            ->assertJsonPath('markup_pct', 15)
            ->assertJsonPath('effective_markup_pct', 15);

        $this->assertDatabaseHas('affiliates', ['id' => $affiliate->id, 'markup_pct' => 15]);
        $this->assertDatabaseHas('affiliate_markup_changes', [
            'affiliate_id' => $affiliate->id,
            'old_pct' => 10,
            'new_pct' => 15,
            'source' => 'portal',
        ]);
    }

    public function test_update_is_hard_capped_at_max_markup_pct(): void
    {
        $affiliate = $this->affiliate(['markup_pct' => 10, 'max_markup_pct' => 25]);

        $this->withToken($this->tokenFor($affiliate))
            ->putJson('/api/affiliate/storefront/pricing', ['markup_pct' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors('markup_pct');

        $this->assertDatabaseHas('affiliates', ['id' => $affiliate->id, 'markup_pct' => 10]);
        $this->assertDatabaseCount('affiliate_markup_changes', 0);
    }

    public function test_show_clamps_a_stale_markup_above_a_lowered_ceiling(): void
    {
        // markup_pct 20 was valid when set; the admin later dropped the
        // ceiling to 12 — the storefront must price at 12, not 20.
        $affiliate = $this->affiliate(['markup_pct' => 20, 'max_markup_pct' => 12]);

        $this->withToken($this->tokenFor($affiliate))
            ->getJson('/api/affiliate/storefront/pricing')
            ->assertOk()
            ->assertJsonPath('markup_pct', 20)
            ->assertJsonPath('effective_markup_pct', 12)
            ->assertJsonPath('max_markup_pct', 12);
    }

    public function test_preview_prices_real_packages_and_flags_the_wholesale_rate(): void
    {
        $affiliate = $this->affiliate(['markup_pct' => 10]);
        $this->package(1000, 1200);

        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Silver', 'monthly_fee_sen' => 5000, 'markup_percent' => 8,
            'is_active' => true, 'sort_order' => 1,
        ]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Active->value,
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addDays(30),
        ]);

        $response = $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/storefront/pricing/preview', ['markup_pct' => 20])
            ->assertOk()
            ->assertJsonPath('wholesale_rate_active', true);

        // wholesale base = 1000 * 1.08 = 1080; affiliate margin = 20% => 216.
        $this->assertSame(1296, $response->json('rows.0.customer_price_sen'));
        $this->assertSame(216, $response->json('rows.0.your_margin_sen'));
    }

    public function test_preview_flags_a_lapsed_subscription(): void
    {
        $affiliate = $this->affiliate();
        $this->package(1000, 1200);

        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Silver', 'monthly_fee_sen' => 5000, 'markup_percent' => 8,
            'is_active' => true, 'sort_order' => 1,
        ]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Lapsed->value,
            'current_period_started_at' => now()->subMonths(2),
            'next_charge_at' => now()->subMonth(),
        ]);

        $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/storefront/pricing/preview', ['markup_pct' => 10])
            ->assertOk()
            ->assertJsonPath('wholesale_rate_active', false);
    }

    public function test_a_deactivated_affiliate_cannot_change_markup(): void
    {
        $affiliate = $this->affiliate(['status' => 'deactivated']);

        $this->withToken($this->tokenFor($affiliate))
            ->putJson('/api/affiliate/storefront/pricing', ['markup_pct' => 5])
            ->assertStatus(403);
    }
}
