<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Membership\MembershipSessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public, no-auth catalog (ADR-011) — the storefront's real data
 * source, replacing storefront/src/lib/placeholder-data.ts (docs/prd.md
 * §14/§15's NEXT SESSION pointer, "public catalog endpoint"). Every
 * assertion here also proves what must NEVER be present: cost_price/
 * standard_selling_price/markup_percent/supplier_id/supplier_package_ref
 * (GameController::packages()'s own admin-only fields).
 */
class CatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: these endpoints resolve the platform storefront via
        // Affiliate::primary(), which fails loud when it is absent.
        $this->primaryAffiliate();
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    public function test_index_lists_only_active_games(): void
    {
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Game::query()->create(['name' => 'Discontinued Game', 'slug' => 'discontinued', 'is_active' => false]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $this->assertSame(['Free Fire Global'], collect($response->json())->pluck('name')->all());
    }

    public function test_index_never_leaks_internal_fields(): void
    {
        Game::query()->create([
            'name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true,
            'supplier_mappings' => [['supplier_id' => 1, 'product_ref' => 'secret-ref']],
            'validation_rules' => ['extra_field' => 'server_id'],
        ]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $game = $response->json()[0];
        $this->assertArrayNotHasKey('supplier_mappings', $game);
        $this->assertArrayNotHasKey('player_validator_profile_id', $game);
        $this->assertSame('server_id', $game['extra_field']);
    }

    public function test_index_includes_price_from_the_cheapest_active_package(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 480, 'standard_selling_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '10 Diamonds (inactive)', 'cost_price' => 50, 'standard_selling_price' => 60,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'C', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        // Platform Owner affiliate markup_pct=0 (ADR-013) — selling_price equals standard_selling_price in MVP.
        $this->assertSame(300, $response->json()[0]['price_from_sen']);
    }

    public function test_index_price_from_is_null_when_no_active_packages_exist(): void
    {
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $this->assertNull($response->json()[0]['price_from_sen']);
    }

    public function test_show_returns_an_active_game_by_slug_with_seo_fields(): void
    {
        Game::query()->create([
            'name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true,
            'seo_title' => 'Top Up Free Fire Diamonds', 'seo_description' => 'Fast Free Fire top-up.',
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global');

        $response->assertOk();
        $response->assertJsonPath('slug', 'free-fire-global');
        $response->assertJsonPath('seo_title', 'Top Up Free Fire Diamonds');
    }

    public function test_show_404s_for_an_inactive_game(): void
    {
        Game::query()->create(['name' => 'Discontinued', 'slug' => 'discontinued', 'is_active' => false]);

        $this->getJson('/api/catalog/games/discontinued')->assertNotFound();
    }

    public function test_show_404s_for_an_unknown_slug(): void
    {
        $this->getJson('/api/catalog/games/does-not-exist')->assertNotFound();
    }

    public function test_packages_lists_only_active_packages_with_a_public_safe_shape(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'markup_percent' => 12.5, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds (inactive)', 'cost_price' => 480, 'standard_selling_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame('50 Diamonds', $packages[0]['name']);
        $this->assertSame(300, $packages[0]['selling_price_sen']);
        foreach (['cost_price', 'standard_selling_price', 'markup_percent', 'supplier_id', 'supplier_package_ref'] as $secretField) {
            $this->assertArrayNotHasKey($secretField, $packages[0]);
        }
    }

    public function test_packages_exposes_has_denomination_and_has_catalog_code_flags(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A1', 'is_active' => true,
            'denomination' => 86, 'catalog_code' => null,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'cost_price' => 700, 'standard_selling_price' => 800,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A2', 'is_active' => true,
            'denomination' => null, 'catalog_code' => 'WP01',
        ]);

        $response = $this->getJson('/api/catalog/games/mlbb/packages');

        $response->assertOk();
        $packages = collect($response->json())->keyBy('name');

        $this->assertTrue($packages['86 Diamonds']['has_denomination']);
        $this->assertFalse($packages['86 Diamonds']['has_catalog_code']);

        $this->assertFalse($packages['Weekly Pass']['has_denomination']);
        $this->assertTrue($packages['Weekly Pass']['has_catalog_code']);
    }

    public function test_packages_applies_affiliate_markup_to_the_selling_price(): void
    {
        $this->primaryAffiliate()->update(['markup_pct' => 10]);
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        // 300 + 10% = 330.
        $this->assertSame(330, $response->json()[0]['selling_price_sen']);
    }

    /**
     * ADR-060 PR-4c: the catalog prices per `X-Storefront-Host` brand —
     * a third-party affiliate's storefront shows its own wholesale-tier
     * + margin price; the header-less primary storefront is unchanged.
     */
    public function test_packages_are_priced_per_storefront_brand(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell', 'markup_pct' => 10, 'status' => 'active',
        ]);
        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Silver', 'monthly_fee_sen' => 5000, 'markup_percent' => 20, 'is_active' => true, 'sort_order' => 1,
        ]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id, 'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Active->value,
            'current_period_started_at' => now(), 'next_charge_at' => now()->addDays(30),
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);

        // Brand: wholesale base round(1000 * 1.20) = 1200, + 10% margin = 1320.
        $this->getJson('/api/catalog/games/free-fire-global/packages', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertOk()
            ->assertJsonPath('0.selling_price_sen', 1320);

        // Primary storefront (no header): standard 1200, markup_pct 0 — unchanged.
        $this->getJson('/api/catalog/games/free-fire-global/packages')
            ->assertOk()
            ->assertJsonPath('0.selling_price_sen', 1200);
    }

    /**
     * ADR-034: two active packages sharing a (game_id, denomination)
     * equivalence key are the same product sold by two suppliers —
     * the storefront must show only the cheaper one, never both.
     */
    public function test_packages_dedups_by_denomination_keeping_the_cheaper_one(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (Gamevion)', 'denomination' => 14,
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV14', 'is_active' => true,
        ]);
        $cheaper = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (Digiflazz)', 'denomination' => 14,
            'cost_price' => 480, 'standard_selling_price' => 550,
            'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'DF14', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame($cheaper->id, $packages[0]['id']);
        $this->assertSame(550, $packages[0]['selling_price_sen']);
    }

    /**
     * Non-integer-amount products (bundles/passes) stay null forever
     * (ADR-034's own Consequence) — must never be collapsed together
     * just because they share the same null "value".
     */
    public function test_packages_with_null_denomination_are_never_deduped(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Starlight Membership', 'denomination' => null,
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-SL', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'denomination' => null,
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-WP', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    /**
     * ADR-075's catalog-code addendum (2026-09-04): the same dedup
     * rule as denomination, applied to bundle/pass packages via
     * catalog_code instead — two suppliers' equivalent "Weekly Pass"
     * (admin-linked via the same catalog_code) must show only the
     * cheaper one, same as a real-value package would.
     */
    public function test_packages_dedups_by_catalog_code_keeping_the_cheaper_one(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (Gamevion)', 'catalog_code' => 'P1',
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV-WP', 'is_active' => true,
        ]);
        $cheaper = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (Digiflazz)', 'catalog_code' => 'P1',
            'cost_price' => 480, 'standard_selling_price' => 550,
            'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'DF-WP', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame($cheaper->id, $packages[0]['id']);
        $this->assertSame(550, $packages[0]['selling_price_sen']);
    }

    /**
     * A catalog_code group and a denomination group never interact —
     * even if a bundle/pass's catalog_code happens to look like a
     * denomination value elsewhere, they occupy different columns and
     * are never compared against one another.
     */
    public function test_packages_catalog_code_and_denomination_groups_never_collide(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds', 'denomination' => 14,
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV14', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'catalog_code' => 'P1',
            'cost_price' => 400, 'standard_selling_price' => 450,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-WP', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    /**
     * Deterministic tie-break (lower package id) when two duplicate
     * packages price identically — not specified by the ADR itself,
     * but the pick must be stable across requests, not arbitrary.
     */
    public function test_packages_dedup_tie_breaks_on_lower_package_id(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $first = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (A)', 'denomination' => 14,
            'cost_price' => 500, 'standard_selling_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A14', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (B)', 'denomination' => 14,
            'cost_price' => 500, 'standard_selling_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B14', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame($first->id, $packages[0]['id']);
    }

    /**
     * ADR-034 follow-up, founder feedback 2026-08-25: same reasoning
     * as GameController::packages()'s own admin-side ordering change —
     * smallest denomination first reads as cheapest-first, sorts
     * numerically instead of alphabetically-by-name. Packages without
     * a curated denomination sort last, by name.
     */
    public function test_packages_orders_by_denomination_ascending_with_nulls_last(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);

        Package::query()->create([
            'game_id' => $game->id, 'name' => '10209 Diamonds', 'denomination' => 10209,
            'cost_price' => 61805, 'standard_selling_price' => 71076,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '13 + 1 Diamonds', 'denomination' => 14,
            'cost_price' => 94, 'standard_selling_price' => 103,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '1252 + 194 Diamonds', 'denomination' => null,
            'cost_price' => 9345, 'standard_selling_price' => 10747,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'C', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '1192 Diamonds', 'denomination' => 1192,
            'cost_price' => 7281, 'standard_selling_price' => 8373,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'D', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $this->assertSame(
            ['13 + 1 Diamonds', '1192 Diamonds', '10209 Diamonds', '1252 + 194 Diamonds'],
            collect($response->json())->pluck('name')->all(),
        );
    }

    public function test_packages_404s_for_an_inactive_game(): void
    {
        Game::query()->create(['name' => 'Discontinued', 'slug' => 'discontinued', 'is_active' => false]);

        $this->getJson('/api/catalog/games/discontinued/packages')->assertNotFound();
    }

    /**
     * ADR-027's 2026-08-29 addendum, decision 21: omitted entirely
     * (never null) while the kill switch is off — its seeded default.
     */
    public function test_packages_omits_member_price_when_membership_feature_is_disabled(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        $this->assertArrayNotHasKey('member_price_sen', $response->json()[0]);
    }

    /**
     * Decisions 16/20/21: once enabled, member_price_sen reflects only
     * the best-value tier (highest discount_percent — the seeded Tier 2
     * at 80%), computed via MembershipPricingService against the
     * package's own markup_percent, not a flat member-wide price.
     */
    public function test_packages_includes_best_tier_member_price_when_enabled(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        // Tier 2 (seeded 80% discount): effective markup 15% * (1-0.8) = 3% -> round(1000 * 1.03) = 1030.
        $this->assertSame(1030, $response->json()[0]['member_price_sen']);
    }

    /**
     * Bug fix, 2026-08-30: a real Tier 1 member's browsing session must
     * see their own tier's price (1075, effective markup 15%*(1-0.5))
     * pre-payment, not Tier 2's anonymous "best tier" anchor (1030) —
     * previously CatalogController::packages() always used the
     * highest-discount plan regardless of who was asking, even though
     * CheckoutService already charged the member correctly at their own
     * tier. `member_price_personalized` distinguishes the two so the
     * storefront never shows a price it won't actually honor at checkout.
     */
    public function test_packages_uses_the_authenticated_members_own_tier_price_not_the_anonymous_anchor(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();

        $tier1 = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'tier1-member@example.com',
            'membership_plan_id' => $tier1->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 30000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = app(MembershipSessionTokenService::class)->issue($this->primaryAffiliate()->id, 'tier1-member@example.com');

        // Anonymous request still gets Tier 2's anchor, unaffected.
        $anonymous = $this->getJson('/api/catalog/games/free-fire-global/packages');
        $anonymous->assertJsonPath('0.member_price_sen', 1030);
        $this->assertArrayNotHasKey('member_price_personalized', $anonymous->json()[0]);

        // The Tier 1 member's own request gets their real tier's price, flagged as personalized.
        $memberResponse = $this->getJson(
            '/api/catalog/games/free-fire-global/packages',
            ['Authorization' => "Bearer {$token}"],
        );
        $memberResponse->assertJsonPath('0.member_price_sen', 1075);
        $memberResponse->assertJsonPath('0.member_price_personalized', true);

        // Re-querying anonymously afterward must still be Tier 2's anchor — no cache bleed between the two.
        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1030);
    }

    /**
     * Decision 18: a tier edit (or the kill switch, tested separately
     * below) must invalidate every game's packages cache at once, not
     * just the one the admin happened to load most recently — proven
     * end-to-end via the real admin endpoint, same discipline as this
     * file's other cache-invalidation tests.
     */
    public function test_public_packages_cache_is_invalidated_when_a_membership_tier_discount_changes(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();

        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1030);

        $tier2 = MembershipPlan::query()->orderByDesc('discount_percent')->first();
        $this->putJson("/api/membership-plans/{$tier2->id}", [
            'fee_sen' => $tier2->fee_sen,
            'quota_sen' => $tier2->quota_sen,
            'discount_percent' => 90,
        ])->assertOk();

        // Effective markup 15% * (1-0.9) = 1.5% -> round(1000 * 1.015) = 1015.
        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1015);
    }

    /**
     * Decision 20: toggling the kill switch off must hide member_price_sen
     * again immediately, not just wait out the TTL.
     */
    public function test_public_packages_cache_is_invalidated_when_the_kill_switch_is_toggled_off(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();
        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1030);

        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => false])->assertOk();

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');
        $this->assertArrayNotHasKey('member_price_sen', $response->json()[0]);
    }

    /**
     * member_price_sen is computed live from Package.markup_percent, not
     * a snapshot — a real admin edit to a package's own markup (not the
     * membership tier's discount) must change it too. Proven via the
     * real PackageController::updateMarkup endpoint, which invalidates
     * this cache through GameController::forgetPackagesCache() ->
     * CatalogController::forgetPackagesCache() — the same tagged-store
     * fix this ADR's own cache migration needed (see that method's
     * comment) applies here as much as to a direct membership_plans edit.
     */
    public function test_member_price_follows_a_real_package_markup_edit(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();
        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1030);

        // Real admin edit: package markup 15% -> 10% (through the real endpoint, not the model directly).
        $this->patchJson("/api/packages/{$package->id}/markup", ['markup_percent' => 10])->assertOk();

        // Effective markup 10% * (1-0.8) = 2% -> round(1000 * 1.02) = 1020.
        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonPath('0.member_price_sen', 1020);
    }

    /**
     * ADR-014 discipline extended to the public catalog: reuses the
     * exact same GameController::forgetIndexCache()/forgetPackagesCache()
     * choke points every admin write already calls through — proven
     * end-to-end via the real admin endpoints, not by calling a cache
     * helper directly.
     */
    public function test_public_index_cache_is_invalidated_when_an_admin_updates_a_game(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $this->getJson('/api/catalog/games')->assertJsonPath('0.name', 'Free Fire Global');

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->putJson("/api/games/{$game->id}", [
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => false,
        ])->assertOk();

        $this->getJson('/api/catalog/games')->assertJsonCount(0);
    }

    public function test_public_packages_cache_is_invalidated_when_an_admin_changes_a_packages_status(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonCount(1);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => false])->assertOk();

        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonCount(0);
    }

    /**
     * Regression test for a real bug found live, 2026-07-26 (same bug
     * class as HeroSlideControllerTest's own regression test): this
     * app's default `database` cache store corrupts any raw object
     * nested inside an otherwise-plain cached array on the next read.
     * `created_at` was left as a raw Carbon instance here — confirmed
     * live it broke on a warm-cache read (`{"__PHP_Incomplete_Class_
     * Name":"Illuminate\\Support\\Carbon", ...}` instead of a date
     * string). Fixed via `?->toISOString()`.
     *
     * ADR-077 PR-2: The app moved to `redis` and tags, so `database` store
     * is no longer tested here directly as it doesn't support tags.
     */
    public function test_index_cache_value_is_fully_scalar(): void
    {
        $brandId = Affiliate::primary()->id;
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $first = $this->getJson('/api/catalog/games');
        $first->assertOk();
        $this->assertSame('Free Fire Global', $first->json()[0]['name']);
        $this->assertIsString($first->json()[0]['created_at']);

        // Check the tagged array store to ensure no Carbon objects leaked.
        $cached = Cache::tags(['catalog.index', "catalog.index.brand.{$brandId}"])->get("catalog.public.games.index.brand.{$brandId}");
        $this->assertIsString($cached[0]['created_at']);

        $second = $this->getJson('/api/catalog/games');
        $second->assertOk();
        $this->assertSame('Free Fire Global', $second->json()[0]['name']);
        $this->assertIsString($second->json()[0]['created_at']);
    }
}
