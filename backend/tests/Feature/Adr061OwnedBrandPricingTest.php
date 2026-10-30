<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PackageMarkupService;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-061 2026-10-30 addendum: an owned brand is the platform under
 * another name. Whatever markup or wholesale tier it carries in the
 * database, it prices on the standard chain exactly as the primary
 * brand does, and every margin books as `platform_profit`.
 *
 * `Affiliate::storefrontPriceTerms()` is the one seam that decides the
 * (tier markup, brand markup) pair; these tests pin the seam itself and
 * every path that reads it.
 */
class Adr061OwnedBrandPricingTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'shop.owned.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        PaymentMethod::query()->create([
            'channel_code' => 'FPX_ABMB', 'label' => 'Test Channel', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 210,
        ]);
        $this->app->bind('payment-gateway.chip', fn () => new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-owned-test']);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => $paymentRequestId,
                    'actions' => ['desktop_web_checkout_url' => 'https://gate.chip-in.asia/p/x'],
                ]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        });
    }

    private function tier(float $markup = 20.0, string $name = 'Wholesale'): AffiliateMembershipTier
    {
        return AffiliateMembershipTier::query()->create([
            'name' => $name, 'monthly_fee_sen' => 0, 'markup_percent' => $markup,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function subscribe(Affiliate $affiliate, AffiliateMembershipTier $tier, string $status = 'active'): void
    {
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => $status,
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addDays(30),
        ]);
    }

    /** A brand with a domain, an active 20% tier and a 10% own markup (ceiling 30%). */
    private function brand(bool $owned, ?float $max = 30.0, float $markup = 10.0, bool $withTier = true): Affiliate
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => $owned ? 'Owned Brand Co' : 'Third Party Co',
            'markup_pct' => $markup, 'max_markup_pct' => $max, 'status' => 'active', 'is_owned' => $owned,
        ]);
        if ($withTier) {
            $this->subscribe($affiliate, $this->tier());
        }
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => self::HOST,
            'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
            'payments_approved_at' => now(),
        ]);

        return $affiliate;
    }

    /** @return array{game: Game, package: Package} cost 1000, standard 1200 */
    private function gameAndPackage(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 1000, 'standard_selling_price' => 1200,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    private function payload(Game $game, Package $package): array
    {
        return [
            'game_id' => $game->id, 'package_id' => $package->id,
            'customer_email' => 'buyer@example.com', 'customer_name' => 'Buyer One',
            'customer_phone' => '0123456789', 'player_id' => '123456789',
            'channel_code' => 'FPX_ABMB', 'idempotency_key' => (string) Str::uuid(),
        ];
    }

    // ---- the seam -------------------------------------------------------

    public function test_an_owned_brand_prices_with_no_tier_and_no_markup_whatever_it_stores(): void
    {
        $brand = $this->brand(owned: true);

        $this->assertSame(['tier' => null, 'markup' => 0.0], $brand->fresh(['subscription.tier'])->storefrontPriceTerms());
    }

    public function test_the_primary_brand_resolves_to_no_tier_and_no_markup(): void
    {
        $this->assertSame(['tier' => null, 'markup' => 0.0], $this->primaryAffiliate()->storefrontPriceTerms());
    }

    /**
     * Parity: for a brand that is NOT owned the seam must return exactly
     * what the old inline expressions did — `wholesaleTierMarkupPct()` and
     * the raw markup (now clamped to its ceiling; a null ceiling is none).
     */
    public function test_a_third_party_brand_resolves_exactly_as_the_old_inline_reads_did(): void
    {
        $rows = [
            // [subscription status|null, markup, max, expected tier, expected markup]
            'active, under ceiling' => ['active', 10.0, 30.0, 20.0, 10.0],
            'grace, under ceiling' => ['grace', 10.0, 30.0, 20.0, 10.0],
            'lapsed falls to no tier' => ['lapsed', 10.0, 30.0, null, 10.0],
            'no subscription' => [null, 10.0, 30.0, null, 10.0],
            'markup equals ceiling' => ['active', 30.0, 30.0, 20.0, 30.0],
            'markup above ceiling is clamped' => ['active', 10.0, 5.0, 20.0, 5.0],
            'null ceiling is no ceiling' => ['active', 10.0, null, 20.0, 10.0],
            'zero markup' => ['active', 0.0, 30.0, 20.0, 0.0],
        ];

        foreach ($rows as $label => [$status, $markup, $max, $tier, $expectedMarkup]) {
            $brand = Affiliate::query()->create([
                'business_name' => "Brand {$label}", 'markup_pct' => $markup, 'max_markup_pct' => $max, 'status' => 'active',
            ]);
            if ($status !== null) {
                $this->subscribe($brand, $this->tier(20.0, "T {$label}"), $status);
            }

            $this->assertSame(
                ['tier' => $tier, 'markup' => $expectedMarkup],
                $brand->fresh(['subscription.tier'])->storefrontPriceTerms(),
                $label,
            );
        }
    }

    // ---- every path that prices a storefront ------------------------------

    public function test_checkout_prices_an_owned_brand_as_the_platform_with_no_affiliate_profit(): void
    {
        $brand = $this->brand(owned: true);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame($brand->id, $order->affiliate_id);
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertSame(1200, $order->selling_price);       // the package's standard price, not 1000 × 1.20 × 1.10
        $this->assertSame(200, $order->platform_profit);
        $this->assertSame(0, $order->affiliate_profit);
        $this->assertNull($order->wholesale_markup_pct);
        $this->assertSame('0.00', $order->affiliate_markup_pct);
    }

    public function test_an_owned_brand_prices_a_combo_exactly_as_the_standard_price_down_to_the_sen(): void
    {
        $this->brand(owned: true, withTier: true);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        // A combo's standard price is summed per leg, so it can sit 1 sen off cost × 1.15.
        $package->update(['cost_price' => 261610, 'standard_selling_price' => 300851]);

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $this->assertSame(300851, Order::query()->sole()->selling_price);
    }

    public function test_preview_totals_matches_checkout_for_an_owned_brand(): void
    {
        $this->brand(owned: true);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id, 'package_id' => $package->id, 'channel_code' => 'FPX_ABMB',
        ], ['X-Storefront-Host' => self::HOST])->assertOk();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $preview->assertJsonPath('selling_price_sen', 1200);
        $preview->assertJsonPath('selling_price_sen', Order::query()->sole()->selling_price);
    }

    public function test_the_catalog_lists_an_owned_brand_at_the_standard_price(): void
    {
        $this->brand(owned: true);
        $this->gameAndPackage();

        $this->getJson('/api/catalog/games/free-fire-global/packages', ['X-Storefront-Host' => self::HOST])
            ->assertOk()
            ->assertJsonPath('0.selling_price_sen', 1200);
    }

    public function test_a_third_party_brand_still_prices_through_its_tier_and_markup(): void
    {
        $this->brand(owned: false);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame('affiliate', $order->pricing_basis->value);
        $this->assertSame(1320, $order->selling_price);  // round(1000 × 1.20) = 1200, + 10% = 1320
        $this->assertSame(200, $order->platform_profit);
        $this->assertSame(120, $order->affiliate_profit);
    }

    public function test_a_third_party_markup_above_its_ceiling_is_charged_at_the_ceiling(): void
    {
        $this->brand(owned: false, max: 5.0, markup: 10.0);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame(1260, $order->selling_price);  // 1200 + 5%
        $this->assertSame(60, $order->affiliate_profit);
        $this->assertSame('5.00', $order->affiliate_markup_pct);
    }

    public function test_a_null_ceiling_charges_the_admin_set_markup(): void
    {
        $this->brand(owned: false, max: null, markup: 10.0);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => self::HOST])->assertCreated();

        $this->assertSame(1320, Order::query()->sole()->selling_price);
    }

    /**
     * Membership is owned-brand-only and an owned brand now charges the
     * package's standard price, so a member (markup cut by the plan's
     * discount) can never pay more than a guest. A combo's standard price
     * is summed per leg and can sit 1–2 sen off `cost × (1 + markup)`
     * (production: 29 of 1,257), which only matters at a 0% discount.
     */
    public function test_a_member_never_pays_more_than_the_owned_brand_guest_price(): void
    {
        $markupService = app(PackageMarkupService::class);
        $memberPricing = app(MembershipPricingService::class);
        $pricing = app(PricingService::class);
        $terms = $this->brand(owned: true)->fresh(['subscription.tier'])->storefrontPriceTerms();

        // [cost, markup %, standard price] — the third row is a combo whose per-leg rounding lands 1 sen under.
        $packages = [
            [250, 15.0, $markupService->calculateStandardSellingPrice(250, 15.0)],
            [19444, 15.0, 22360],
            [261610, 15.0, 300851],
            [1000, 0.0, 1000],
            [987654, 22.5, $markupService->calculateStandardSellingPrice(987654, 22.5)],
        ];

        foreach ($packages as [$cost, $markup, $standard]) {
            $guest = $pricing->calculateForAffiliate($cost, $standard, $terms['tier'], $terms['markup'])->sellingPrice;

            foreach ([50.0, 70.0] as $discount) {
                $member = $memberPricing->calculateMemberPrice($cost, $markup, $discount);
                $this->assertLessThanOrEqual($guest, $member, "cost {$cost}, markup {$markup}%, discount {$discount}%");
            }
        }
    }

    /** The member-savings badge compares against the price a guest sees, so it must read the same seam. */
    public function test_the_member_savings_badge_ignores_a_stale_markup_on_the_primary_brand(): void
    {
        $this->primaryAffiliate()->update(['markup_pct' => 10]);
        ['package' => $package] = $this->gameAndPackage();
        $package->update(['markup_percent' => 15]);

        // Guest price 1200 (standard); member at a 50% discount = 1000 × 1.075 = 1075 → 10.4% saved.
        $this->assertSame(10.4, app(MembershipPricingService::class)->realSavingsPercent($package->fresh(), 50.0));
    }
}
