<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\BlacklistEntry;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Fraud\BlacklistEntryType;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $this->rebindGateway($createSucceeds);
        // Every test drives checkout through channel_code FPX_ABMB —
        // needs a real, active PaymentMethod row now that channel
        // fee/gateway config is DB-backed, not config/checkout.php.
        $this->activeChannel();
    }

    /**
     * Swaps the gateway fake mid-test (e.g. simulating a retry that
     * now succeeds after an earlier attempt failed) without touching
     * the already-created PaymentMethod row — bindGateway() itself
     * can't be called twice in one test, since activeChannel() would
     * try to insert a second row with the same unique channel_code.
     */
    private function rebindGateway(bool $createSucceeds = true): void
    {
        $gateway = $this->fakeGateway($createSucceeds);
        // Rebinding this container key (not PaymentGateway::class
        // directly) is what PaymentGatewayFactory::make('xendit')
        // resolves through — see AppServiceProvider.
        $this->app->bind('payment-gateway.xendit', fn () => $gateway);
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

    /** @return array{game: Game, package: Package} */
    private function validatorEnabledGameAndPackage(array $gameOverrides = []): array
    {
        $profile = PlayerValidatorProfile::query()->create([
            'name' => 'AcidGameShop',
            'key' => 'acidgameshop',
        ]);

        return $this->gameAndPackage(array_merge([
            'player_validator_profile_id' => $profile->id,
            'player_validator_enabled' => true,
        ], $gameOverrides));
    }

    private function recordValidation(Game $game, string $playerId, ?string $serverId = null, array $overrides = []): PlayerValidation
    {
        return PlayerValidation::query()->create(array_merge([
            'game_id' => $game->id,
            'player_id' => $playerId,
            'server_id' => $serverId,
            'status' => 'valid',
            'validated_at' => now(),
        ], $overrides));
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
            // Each call gets its own fresh attempt by default — tests
            // exercising a *repeated* key pass the same idempotency_key
            // explicitly via $overrides.
            'idempotency_key' => (string) Str::uuid(),
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

    /**
     * The real gap this closes: a direct API call could always skip
     * PlayerValidationController entirely — the storefront wizard's
     * "Proceed to Payment" gate is client-side React state only, not a
     * security boundary (ADR-011: guest checkout, no session to own it).
     */
    public function test_rejects_checkout_for_a_validator_enabled_game_with_no_validation_on_record(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_checkout_for_a_validator_enabled_game_with_a_recent_valid_validation(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertCreated();
    }

    public function test_rejects_checkout_when_the_recorded_validation_is_for_a_different_player_id(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '999999999');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_rejects_checkout_when_the_recorded_validation_status_is_not_valid(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789', overrides: ['status' => 'invalid']);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_rejects_checkout_when_the_recorded_validation_has_expired(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789', overrides: ['validated_at' => now()->subMinutes(31)]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_accepts_checkout_when_the_validated_server_id_matches(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);
        $this->recordValidation($game, '123456789', '5001');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, [
            'player_id' => '123456789',
            'server_id' => '5001',
        ]));

        $response->assertCreated();
    }

    public function test_rejects_checkout_for_a_blacklisted_player_id(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_checkout_for_a_blacklisted_email(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::Email->value,
            'value' => 'buyer@example.com',
            'reason' => 'Known fraud contact',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_does_not_block_checkout_for_an_inactive_blacklist_entry(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'previously blocked, now cleared',
            'is_active' => false,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
    }

    public function test_a_blocked_attempt_is_recorded_as_a_hit(): void
    {
        $this->bindGateway();
        $entry = BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package));

        $this->assertDatabaseHas('blacklist_hits', [
            'blacklist_entry_id' => $entry->id,
            'player_id' => '123456789',
        ]);
    }

    /**
     * FRAUD-4: distinct from the general throttle:10,1 - this trips
     * on repeated blacklist-triggered rejections specifically, well
     * before the general throttle's own 10-request ceiling.
     */
    public function test_velocity_guard_blocks_further_attempts_after_repeated_blacklist_hits(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        // Default test threshold (config/fraud.php) is 3.
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();

        // A fresh, otherwise-valid attempt from the same IP is still
        // blocked - the velocity trip applies regardless of whether
        // *this* attempt matches the blacklist.
        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '999999999']));

        $response->assertUnprocessable();
        $this->assertSame(0, Order::query()->count());
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
     * ADR-019's checkout-level idempotency fix — the actual scenario
     * this exists for: a customer double-click or client-side timeout
     * retry re-sends the exact same request. Must return the same
     * order, not create (and pay for) a second one.
     */
    public function test_replays_the_same_order_for_a_repeated_idempotency_key_after_success(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $first->assertCreated();

        $second = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $second->assertOk(); // 200 — a replay, not a newly-created resource
        $second->assertJsonPath('order_number', $first->json('order_number'));

        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The other real branch: the first attempt's payment call failed
     * (Order created, no payment_ref), and the retry with the same key
     * must resume that same Order rather than starting a fresh one.
     */
    public function test_resumes_the_existing_order_when_a_repeated_key_previously_failed_payment(): void
    {
        $this->bindGateway(createSucceeds: false);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $first->assertUnprocessable();
        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->firstOrFail()->payment_ref);

        $this->rebindGateway(createSucceeds: true);
        $second = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));

        $second->assertOk();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('pr-checkout-test', Order::query()->firstOrFail()->payment_ref);
    }

    public function test_a_different_idempotency_key_creates_a_genuinely_separate_order(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();

        $this->assertSame(2, Order::query()->count());
    }
}
