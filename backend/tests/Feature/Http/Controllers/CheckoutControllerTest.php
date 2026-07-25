<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real in-test fake, not Http::fake() — same convention as
     * CheckoutServiceTest/OrderFulfillmentServiceTest: the point is
     * testing CheckoutController's orchestration/validation logic in
     * isolation from any real HTTP layer.
     */
    private function fakeGateway(bool $createSucceeds = true): PaymentGateway
    {
        return new class($createSucceeds) implements PaymentGateway
        {
            public function __construct(private readonly bool $createSucceeds)
            {
            }

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->createSucceeds
                    ? PaymentResponse::success(['payment_request_id' => 'pr-checkout-test'])
                    : PaymentResponse::failure('API_VALIDATION_ERROR', 'bad channel_properties');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => $paymentRequestId,
                    'actions' => ['desktop_web_checkout_url' => 'https://checkout.xendit.co/web/pr-checkout-test'],
                ]);
            }

            public function verifyWebhookSignature(string $providedToken): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        };
    }

    private function bindGateway(bool $createSucceeds = true): void
    {
        $gateway = $this->fakeGateway($createSucceeds);
        // Rebinding this container key (not PaymentGateway::class
        // directly) is what PaymentGatewayFactory::make('xendit')
        // resolves through — see AppServiceProvider.
        $this->app->bind('payment-gateway.xendit', fn () => $gateway);
        // Every test drives checkout through channel_code FPX_ABMB —
        // needs a real, active PaymentMethod row now that channel
        // fee/gateway config is DB-backed, not config/checkout.php.
        $this->activeChannel();
    }

    private function activeChannel(string $channelCode = 'FPX_ABMB', array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create(array_merge([
            'channel_code' => $channelCode,
            'label' => 'Test Channel',
            'category' => 'fpx',
            'gateway' => 'xendit',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 210,
        ], $overrides));
    }

    /** @return array{game: Game, package: Package} */
    private function gameAndPackage(array $gameOverrides = [], array $packageOverrides = []): array
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );
        $game = Game::query()->create(array_merge([
            'name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true,
        ], $gameOverrides));
        $package = Package::query()->create(array_merge([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ], $packageOverrides));

        return ['game' => $game, 'package' => $package];
    }

    private function payload(Game $game, Package $package, array $overrides = []): array
    {
        return array_merge([
            'game_id' => $game->id,
            'package_id' => $package->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer One',
            'customer_phone' => '0123456789',
            'player_id' => '123456789',
            'channel_code' => 'FPX_ABMB',
        ], $overrides);
    }

    public function test_creates_an_order_and_returns_payment_actions(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'pending');
        $response->assertJsonPath(
            'payment_actions.desktop_web_checkout_url',
            'https://checkout.xendit.co/web/pr-checkout-test',
        );

        $order = Order::query()->firstOrFail();
        $this->assertSame($game->id, $order->game_id);
        $this->assertSame($package->id, $order->package_id);
        $this->assertSame(500, $order->selling_price); // reseller_cost_price + 0% reseller markup
        $this->assertSame('pr-checkout-test', $order->payment_ref);
    }

    public function test_seeds_the_single_platform_reseller_if_missing_and_uses_zero_markup(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->assertSame(0, Reseller::query()->count());

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $this->assertSame(1, Reseller::query()->count());
        $reseller = Reseller::query()->firstOrFail();
        $this->assertSame('0.00', (string) $reseller->markup_pct);
        $this->assertSame($reseller->id, Order::query()->firstOrFail()->reseller_id);
    }

    public function test_reuses_the_existing_platform_reseller_instead_of_creating_a_duplicate(): void
    {
        $this->bindGateway();
        Reseller::query()->create(['business_name' => 'Platform Owner', 'markup_pct' => 0, 'status' => 'active']);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $this->assertSame(1, Reseller::query()->count());
    }

    public function test_rejects_checkout_for_a_game_requiring_an_extra_field_without_it(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('server_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_checkout_for_a_game_requiring_an_extra_field_when_provided(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['server_id' => '5001']));

        $response->assertCreated();
        $this->assertSame('5001', Order::query()->firstOrFail()->server_id);
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

    public function test_does_not_require_authentication(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated(); // no Sanctum::actingAs() anywhere in this test — ADR-011
    }

    /**
     * ADR-014: throttle:10,1 — the 11th request from the same IP
     * within a minute must be rejected before it ever reaches
     * CheckoutController, flood-protection independent of validation.
     */
    public function test_rate_limits_repeated_requests_from_the_same_ip(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        }

        $this->postJson('/api/checkout', $this->payload($game, $package))
            ->assertStatus(429);
    }
}
