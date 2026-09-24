<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-060 PR-4c — the money PR. A guest checkout on a third-party
 * affiliate's branded storefront (resolved from `X-Storefront-Host`)
 * must:
 *  - price against THAT brand's wholesale tier + its own margin, not the
 *    primary's,
 *  - freeze the split onto the Order (`platform_profit` = wholesale − cost,
 *    `affiliate_profit` = the affiliate's margin) so the unchanged
 *    `OrderFulfillmentService::creditProfit()` books the ledger split to
 *    the right owners at delivery,
 *  - attribute the Order to the brand (`affiliate_id`), and
 *  - charge exactly what `/checkout/preview-totals` quoted for that brand
 *    (anti-divergence).
 *
 * A lapsed tier falls back to the standard chain (2026-09-05 addendum
 * §3); a header-less request is the primary storefront, byte-identical
 * to before this PR.
 */
class AffiliateStorefrontCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate(); // markup_pct 0 — Affiliate::primary() fallback
        $this->activeChannel();
        $this->bindGateway();
    }

    private function bindGateway(): void
    {
        $gateway = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-affiliate-test']);
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
        };

        $this->app->bind('payment-gateway.chip', fn () => $gateway);
    }

    private function activeChannel(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'channel_code' => 'FPX_ABMB',
            'label' => 'Test Channel',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 210,
        ]);
    }

    /** @return array{game: Game, package: Package} */
    private function gameAndPackage(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds',
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    /**
     * A third-party affiliate brand: `markup_pct` 10, an active wholesale
     * tier at 20% markup, one active custom domain.
     */
    private function affiliateBrand(string $subscriptionStatus = AffiliateSubscriptionStatus::Active->value): Affiliate
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);

        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Silver', 'monthly_fee_sen' => 5000, 'markup_percent' => 20,
            'is_active' => true, 'sort_order' => 1,
        ]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => $subscriptionStatus,
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addDays(30),
        ]);

        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        return $affiliate;
    }

    private function payload(Game $game, Package $package): array
    {
        return [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer One',
            'customer_phone' => '0123456789',
            'player_id' => '123456789',
            'channel_code' => 'FPX_ABMB',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function test_order_is_priced_and_attributed_to_the_resolved_affiliate_brand(): void
    {
        $brand = $this->affiliateBrand();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertCreated();

        $order = Order::query()->sole();

        // cost 1000 → wholesale base = round(1000 * 1.20) = 1200
        // affiliate margin = round(1200 * 0.10) = 120 → selling = 1320
        $this->assertSame('affiliate', $order->pricing_basis->value);
        $this->assertSame($brand->id, $order->affiliate_id);
        $this->assertSame(1000, $order->cost_price);
        $this->assertSame(1200, $order->standard_selling_price);
        $this->assertSame(1320, $order->selling_price);
        $this->assertSame(200, $order->platform_profit);   // wholesale 1200 − cost 1000
        $this->assertSame(120, $order->affiliate_profit);  // the affiliate's own margin
        $this->assertSame(200 + 120, $order->selling_price - $order->cost_price);
        $this->assertSame('20.00', $order->wholesale_markup_pct);
        $this->assertSame('10.00', $order->affiliate_markup_pct);
    }

    /**
     * Bug fix, 2026-09-24: found via a real fixfastapp.com order redirecting
     * back to pekangame.space's /order/status/... (itself brand-scoped, so
     * it 404'd there). The gateway return URL must reflect the resolved
     * affiliate's own domain, not the platform default.
     */
    public function test_checkout_return_url_lands_on_the_affiliates_own_domain(): void
    {
        $this->affiliateBrand();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $gateway = new class implements PaymentGateway
        {
            public ?PaymentRequest $received = null;

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                $this->received = $request;

                return PaymentResponse::success(['payment_request_id' => 'pr-domain-test']);
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
        };
        $this->app->instance('payment-gateway.chip', $gateway);

        $this->postJson('/api/checkout', [
            ...$this->payload($game, $package),
            'channel_properties' => [
                'success_return_url' => 'placeholder',
                'failure_return_url' => 'placeholder',
            ],
        ], ['X-Storefront-Host' => 'shop.acme.com'])->assertCreated();

        $order = Order::query()->sole();
        $this->assertNotNull($gateway->received);
        $this->assertSame(
            "https://shop.acme.com/order/status/{$order->order_number}",
            $gateway->received->channelProperties['success_return_url'],
        );
        $this->assertSame(
            "https://shop.acme.com/order/status/{$order->order_number}",
            $gateway->received->channelProperties['failure_return_url'],
        );
    }

    public function test_preview_totals_matches_what_checkout_charges_for_the_brand(): void
    {
        $this->affiliateBrand();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ], ['X-Storefront-Host' => 'shop.acme.com'])->assertOk();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertCreated();

        $order = Order::query()->sole();
        $preview->assertJsonPath('selling_price_sen', $order->selling_price);
        $preview->assertJsonPath('final_amount_sen', $order->final_amount); // 1320 + 210 fee
    }

    public function test_a_lapsed_tier_falls_back_to_the_standard_chain(): void
    {
        $brand = $this->affiliateBrand(AffiliateSubscriptionStatus::Lapsed->value);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertCreated();

        $order = Order::query()->sole();

        // No wholesale tier: base = standard_selling_price 1200,
        // affiliate margin = round(1200 * 0.10) = 120 → selling = 1320.
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertSame($brand->id, $order->affiliate_id);
        $this->assertSame(1320, $order->selling_price);
        $this->assertSame(200, $order->platform_profit); // standard 1200 − cost 1000
        $this->assertSame(120, $order->affiliate_profit);
        $this->assertNull($order->wholesale_markup_pct);
    }

    public function test_no_header_prices_as_the_primary_storefront(): void
    {
        $this->affiliateBrand(); // exists but not this request's host
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertSame(1200, $order->selling_price); // standard + 0% primary markup
        $this->assertSame(0, $order->affiliate_profit);
        $this->assertSame($this->primaryAffiliate()->id, $order->affiliate_id);
    }

    public function test_an_unknown_host_is_rejected_before_any_order_is_created(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package), ['X-Storefront-Host' => 'nope.example'])
            ->assertNotFound();

        $this->assertSame(0, Order::query()->count());
    }

    /**
     * ADR-060 PR-4d, decision 5: a voucher issued on the affiliate brand
     * is not redeemable on the primary storefront (no header) — the
     * checkout rejects it exactly like an ownership mismatch.
     */
    public function test_a_voucher_from_another_brand_is_rejected_at_checkout(): void
    {
        $brand = $this->affiliateBrand();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        // Partial-cover — the acme checkout stays Pending at the gateway
        // (no fulfilment), keeping this test to the brand check alone.
        $voucher = Voucher::query()->create([
            'affiliate_id' => $brand->id,
            'code' => 'VC-BRANDSCOPED',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        // No X-Storefront-Host → primary storefront; the brand-A voucher is invalid here.
        $this->postJson('/api/checkout', [...$this->payload($game, $package), 'voucher_code' => $voucher->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher_code');

        // Same voucher on its own brand's storefront works.
        $this->postJson(
            '/api/checkout',
            [...$this->payload($game, $package), 'voucher_code' => $voucher->code],
            ['X-Storefront-Host' => 'shop.acme.com'],
        )->assertCreated();
    }
}
