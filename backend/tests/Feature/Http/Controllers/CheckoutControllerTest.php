<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateGame;
use App\Models\Game;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: creating an order, packages, channels, brand rules and request validation. */
class CheckoutControllerTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    public function test_creates_an_order_and_returns_payment_actions(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'pending');
        $response->assertJsonPath(
            'payment_actions.desktop_web_checkout_url',
            'https://gate.chip-in.asia/p/pr-checkout-test',
        );

        $order = Order::query()->firstOrFail();
        $this->assertSame('storefront', $order->placed_via->value);
        $this->assertSame($game->id, $order->game_id);
        $this->assertSame($package->id, $order->package_id);
        $this->assertSame(500, $order->selling_price); // standard_selling_price + 0% affiliate markup
        $this->assertSame('pr-checkout-test', $order->payment_ref);
    }

    /**
     * ADR-022's newest addendum, decision 1: the Order snapshots which
     * gateway/channel it checked out with (from the PaymentMethod row
     * already resolved above), so ReconcilePendingPaymentsCommand can
     * resolve the correct PaymentGateway per order once a second
     * gateway exists.
     */
    public function test_stamps_the_resolved_payment_methods_gateway_and_channel_code_onto_the_order(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('chip', $order->payment_gateway);
        $this->assertSame('FPX_ABMB', $order->channel_code);
    }

    /** 2026-09-29 audit: a game the brand hid was off the catalog but still buyable by direct POST. */
    public function test_rejects_checkout_and_preview_of_a_game_the_brand_has_hidden(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        AffiliateGame::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'game_id' => $game->id,
            'is_visible' => false,
        ]);

        $this->postJson('/api/checkout', $this->payload($game, $package))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('package_id');
        $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ])->assertUnprocessable()->assertJsonValidationErrors('package_id');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_attaches_the_primary_affiliate_to_the_order_at_zero_markup(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $affiliate = Affiliate::query()->where('is_primary', true)->sole();
        $this->assertSame('0.00', (string) $affiliate->markup_pct);
        $this->assertSame($affiliate->id, Order::query()->firstOrFail()->affiliate_id);
    }

    public function test_checkout_does_not_silently_create_a_storefront_when_the_primary_is_missing(): void
    {
        $this->bindGateway();
        Affiliate::query()->forceDelete();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        // ADR-061: Affiliate::primary() throws (ModelNotFoundException via
        // sole()) rather than the old firstOrCreate silently conjuring a
        // storefront — a misconfigured environment is a loud, actionable
        // failure, never a half-working checkout with a phantom affiliate.
        $this->postJson('/api/checkout', $this->payload($game, $package))
            ->assertNotFound();

        $this->assertSame(0, Affiliate::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_a_package_that_does_not_belong_to_the_given_game(): void
    {
        $this->bindGateway();
        ['game' => $game] = $this->gameAndPackage();
        ['package' => $otherPackage] = $this->gameAndPackage(
            ['name' => 'Mobile Legends', 'slug' => 'mobile-legends'],
            ['supplier_package_ref' => 'B'],
        );

        $response = $this->postJson('/api/checkout', $this->payload($game, $otherPackage));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('package_id');
    }

    public function test_rejects_an_inactive_package(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([], ['is_active' => false]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
    }

    public function test_rejects_an_inactive_game(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(['is_active' => false]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
    }

    public function test_rejects_an_unknown_channel_code(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'BITCOIN']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('channel_code');
    }

    public function test_rejects_a_deactivated_channel_code(): void
    {
        $this->bindGateway();
        $this->activeChannel('GRABPAY', ['category' => 'ewallet', 'is_active' => false]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'GRABPAY']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('channel_code');
    }

    public function test_rejects_a_nonexistent_package_id(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['package_id' => $package->id + 999999]),
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('package_id');
    }

    public function test_keeps_the_order_pending_when_the_gateway_rejects_the_payment_request(): void
    {
        $this->bindGateway(createSucceeds: false);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('payment');
        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->firstOrFail()->payment_ref);
    }

    public function test_logs_a_checkout_failure(): void
    {
        Log::spy();
        $this->bindGateway(createSucceeds: false);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Checkout failed'
                && $context['game_id'] === $game->id
                && $context['package_id'] === $package->id,
        );
    }

    public function test_does_not_require_authentication(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated(); // no Sanctum::actingAs() anywhere in this test — ADR-011
    }

    /** ADR-028 decision 8: maintenance mode blocks new checkout submissions only. */
    public function test_rejects_checkout_when_maintenance_mode_is_on(): void
    {
        $this->bindGateway();
        PlatformSettings::query()->create([
            'maintenance_mode' => true,
            'maintenance_message' => 'Back in 10 minutes.',
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertStatus(503);
        $response->assertJson(['message' => 'Back in 10 minutes.']);
    }

    public function test_rejects_checkout_without_an_idempotency_key(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package);
        unset($payload['idempotency_key']);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('idempotency_key');
    }

    /**
     * customer_phone made required 2026-07-30 — Gamevion's real order
     * endpoint rejects delivery with no phone number (see
     * CreateCheckoutRequest's doc comment, docs/adr.md's ADR-006
     * addendum). Was previously nullable; a paid order used to only
     * discover the missing phone at the delivery step, too late to
     * matter to the customer.
     */
    public function test_rejects_checkout_without_a_customer_phone(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package);
        unset($payload['customer_phone']);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('customer_phone');
    }

    /**
     * `channel_properties` inner keys allowlisted 2026-08-21 — the only
     * keys the storefront has ever sent (success/failure_return_url) are
     * overwritten server-side anyway (CheckoutService::requestPayment()),
     * so a direct API caller stuffing in extra keys has no legitimate use
     * and previously flowed straight through to the gateway unfiltered.
     */
    public function test_rejects_channel_properties_with_an_unsupported_key(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, [
            'channel_properties' => [
                'success_return_url' => 'https://example.com/ok',
                'card_details' => ['cvn' => '123'],
            ],
        ]);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('channel_properties');
    }

    public function test_accepts_channel_properties_with_only_allowlisted_keys(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, [
            'channel_properties' => [
                'success_return_url' => 'https://example.com/ok',
                'failure_return_url' => 'https://example.com/fail',
            ],
        ]);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertCreated();
    }
}
