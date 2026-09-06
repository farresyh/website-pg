<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-024 decision #1's "Apply" button — read-only, never locks or
 * mutates a voucher's remaining balance (see VoucherServiceTest for
 * the locked redeem()/commit()/restore() behavior).
 */
class VoucherPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: these endpoints resolve the platform storefront via
        // Affiliate::primary(), which fails loud when it is absent.
        $this->primaryAffiliate();
    }

    /** @return array{game: Game, package: Package} */
    private function gameAndPackage(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    public function test_returns_discount_capped_at_selling_price(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-PREVIEW-CTRL',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('selling_price', 500);
        $response->assertJsonPath('discount', 500);
        $response->assertJsonPath('remaining_after', 500);

        // Never locks — remaining is untouched by a preview call.
        $this->assertSame(1000, $voucher->fresh()->remaining);
    }

    public function test_rejects_a_voucher_that_does_not_belong_to_this_customer(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-PREVIEW-CTRL-2',
            'customer_email' => 'owner@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'voucher_code' => $voucher->code,
            'customer_email' => 'stranger@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('voucher_code');
    }

    /**
     * ADR-060 PR-4c: the discount preview is computed against the
     * `X-Storefront-Host` brand's wholesale-tier + margin price, same
     * basis the real checkout charges — never the primary's.
     */
    public function test_selling_price_is_computed_for_the_resolved_brand(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1200,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        $affiliate = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 10, 'status' => 'active']);
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

        $voucher = Voucher::query()->create([
            'affiliate_id' => $affiliate->id,
            'code' => 'KRS-BRAND-PREVIEW', 'customer_email' => 'buyer@example.com',
            'amount' => 300, 'remaining' => 300, 'status' => 'active', 'reason' => 'test',
        ]);

        $response = $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ], ['X-Storefront-Host' => 'shop.acme.com']);

        $response->assertOk();
        // wholesale round(1000 * 1.20) = 1200, + 10% margin = 1320.
        $response->assertJsonPath('selling_price', 1320);
        $response->assertJsonPath('discount', 300);
        $response->assertJsonPath('remaining_after', 0);
    }
}
