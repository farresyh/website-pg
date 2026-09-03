<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\FulfillOrderJob;
use App\Models\BlacklistEntry;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Fraud\BlacklistEntryType;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: checkout resolves the platform's own storefront via
        // Reseller::primary(), which fails loud when it is missing (no
        // more firstOrCreate). Every checkout path needs it present.
        $this->primaryReseller();
    }

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
            public function __construct(private readonly bool $createSucceeds) {}

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
                    'actions' => ['desktop_web_checkout_url' => 'https://gate.chip-in.asia/p/pr-checkout-test'],
                ]);
            }

            public function verifyWebhookSignature(Request $request): bool
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
        // directly) is what PaymentGatewayFactory::make('chip')
        // resolves through — see AppServiceProvider.
        $this->app->bind('payment-gateway.chip', fn () => $gateway);
    }

    private function activeChannel(string $channelCode = 'FPX_ABMB', array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create(array_merge([
            'channel_code' => $channelCode,
            'label' => 'Test Channel',
            'category' => 'fpx',
            'gateway' => 'chip',
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
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
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
            'https://gate.chip-in.asia/p/pr-checkout-test',
        );

        $order = Order::query()->firstOrFail();
        $this->assertSame($game->id, $order->game_id);
        $this->assertSame($package->id, $order->package_id);
        $this->assertSame(500, $order->selling_price); // standard_selling_price + 0% reseller markup
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

    public function test_attaches_the_primary_reseller_to_the_order_at_zero_markup(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
        $reseller = Reseller::query()->where('is_primary', true)->sole();
        $this->assertSame('0.00', (string) $reseller->markup_pct);
        $this->assertSame($reseller->id, Order::query()->firstOrFail()->reseller_id);
    }

    public function test_checkout_does_not_silently_create_a_storefront_when_the_primary_is_missing(): void
    {
        $this->bindGateway();
        Reseller::query()->forceDelete();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        // ADR-061: Reseller::primary() throws (ModelNotFoundException via
        // sole()) rather than the old firstOrCreate silently conjuring a
        // storefront — a misconfigured environment is a loud, actionable
        // failure, never a half-working checkout with a phantom reseller.
        $this->postJson('/api/checkout', $this->payload($game, $package))
            ->assertNotFound();

        $this->assertSame(0, Reseller::query()->count());
        $this->assertSame(0, Order::query()->count());
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

    private function voucher(array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'code' => 'KRS-CHECKOUT-TEST',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => null,
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test voucher',
        ], $overrides));
    }

    /**
     * ADR-024 decision #1/#4 — feeBase (and therefore the gateway-
     * charged fee) is computed on the cash portion only, never the
     * voucher-covered portion. Decision #1: redemption happens after
     * the gateway confirms success, so it's already reflected by the
     * time this response returns.
     */
    public function test_partial_cover_voucher_reduces_fee_base_and_redeems_after_gateway_success(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'pending');

        $order = Order::query()->firstOrFail();
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame(200, $order->voucher_discount);
        // feeBase = 500 - 200 = 300; flat_fee_sen = 210 from activeChannel().
        $this->assertSame(210, $order->transaction_fee);
        $this->assertSame(510, $order->final_amount);
        $this->assertNotNull($order->payment_ref);

        $this->assertSame(0, $voucher->fresh()->remaining);
        $this->assertSame('exhausted', $voucher->fresh()->status);
        $redemption = VoucherRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(200, $redemption->amount);
        $this->assertSame('reserved', $redemption->status);
    }

    /**
     * ADR-024 decision #5 — the real gap this decision closed: a
     * flat-fee channel (activeChannel() seeds flat_fee_sen=210) must
     * not charge that fee at all once the gateway is skipped entirely.
     */
    public function test_full_cover_voucher_skips_gateway_and_settles_immediately(): void
    {
        Queue::fake();
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 1000]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'paid');
        $response->assertJsonPath('payment_actions', []);

        $order = Order::query()->firstOrFail();
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame(500, $order->voucher_discount); // capped at selling_price, not the full 1000 remaining
        $this->assertSame(0, $order->transaction_fee);
        $this->assertSame(0, $order->final_amount);
        $this->assertNull($order->payment_ref);
        $this->assertSame('paid', $order->payment_status->value);

        $this->assertSame(500, $voucher->fresh()->remaining); // 1000 - 500, not fully exhausted

        Queue::assertPushed(
            FulfillOrderJob::class,
            fn ($job) => $job->order->id === $order->id,
        );
    }

    /**
     * ORD-9/ADR-024 decision #3 — an invalid code (here: right code,
     * wrong customer) is rejected before any Order is created, with
     * the same generic message VoucherService::preview() always uses.
     */
    public function test_rejects_checkout_with_a_voucher_that_does_not_belong_to_this_customer(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $voucher = $this->voucher(['customer_email' => 'someone-else@example.com']);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('voucher_code');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1000, $voucher->fresh()->remaining);
    }

    /**
     * ADR-027 Phase 6. cost_price=1000, markup_percent=20 ->
     * standard_selling_price=1200 (guest/standard price). Tier 2's
     * seeded discount_percent=80 -> effectiveMarkupPercent = 20*(1-0.8)
     * = 4% -> member price = 1000*1.04 = 1040.
     */
    private function memberPackage(): array
    {
        return $this->gameAndPackage([], [
            'cost_price' => 1000,
            'standard_selling_price' => 1200,
            'markup_percent' => 20,
        ]);
    }

    private function membershipToken(string $email): string
    {
        return app(MembershipSessionTokenService::class)->issue($this->primaryReseller()->id, $email);
    }

    /**
     * ADR-061 decision 5: a session token minted on another brand's
     * storefront never applies member pricing on this one — the checkout
     * proceeds as a plain guest.
     */
    public function test_a_session_token_from_another_brand_does_not_apply_member_pricing(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $foreignToken = app(MembershipSessionTokenService::class)->issue(999, 'member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$foreignToken}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
    }

    public function test_a_member_with_sufficient_quota_gets_the_member_price_and_decrements_quota(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('member', $order->pricing_basis->value);
        $this->assertSame($membership->id, $order->membership_id);
        $this->assertSame('80.00', $order->member_discount_percent);
        $this->assertSame(1200, $order->normal_selling_price);
        $this->assertSame(1040, $order->selling_price);
        $this->assertSame(40, $order->platform_profit); // 1040 - 1000
        $this->assertSame(0, $order->reseller_profit);

        $this->assertSame(960, $membership->fresh()->quota_remaining_sen); // 2000 - 1040
        $debit = MembershipQuotaDebit::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1040, $debit->amount_sen);
    }

    /**
     * ADR-068 decision 16: a signed-in member's order is always
     * attributed to their OTP-verified membership email, even when a
     * different address is typed into the checkout contact field — so
     * the order can never go missing from their /membership history.
     */
    public function test_a_member_order_is_stored_under_the_membership_email_not_the_typed_one(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'real-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 5000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('real-member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'typo@example.com', 'customer_name' => 'Real Member']),
            ['Authorization' => "Bearer {$token}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('real-member@example.com', $order->customer_email);
        $this->assertSame('Real Member', $order->customer_name); // name is left as typed
    }

    /** ADR-068 decision 16: the bind is keyed on the session, not on member pricing — a quota-exhausted member still gets it. */
    public function test_an_out_of_quota_member_order_is_still_stored_under_the_membership_email(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'broke-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 1, // nowhere near the member price — standard fallback
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('broke-member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'typo@example.com']),
            ['Authorization' => "Bearer {$token}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertSame('broke-member@example.com', $order->customer_email);
    }

    /**
     * ADR-027's 2026-08-29 continued addendum, decision 4: a member
     * order's `reseller_profit` is always 0 — the platform absorbs the
     * entire member discount itself, never the reseller's own margin —
     * regardless of what `Reseller.markup_pct` is actually configured
     * to. Proven here against a genuinely nonzero markup (10%), with a
     * standard order under the identical markup as the contrasting
     * control case: same reseller, same package, same 10% — member
     * gets 0, standard gets a real cut.
     */
    public function test_reseller_profit_is_always_zero_for_a_member_order_even_when_reseller_markup_is_nonzero(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $this->primaryReseller()->update(['markup_pct' => 10]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'markup-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('markup-member@example.com');

        $memberResponse = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'markup-member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );
        $memberResponse->assertCreated();
        $memberOrder = Order::query()->where('customer_email', 'markup-member@example.com')->firstOrFail();

        $this->assertSame('member', $memberOrder->pricing_basis->value);
        $this->assertSame('10.00', $memberOrder->reseller_markup_pct); // snapshotted, but unused for profit
        $this->assertSame(1040, $memberOrder->selling_price); // unaffected by reseller markup
        $this->assertSame(0, $memberOrder->reseller_profit);

        // Control case: same reseller markup, no membership token — reseller must earn a real cut.
        $standardResponse = $this->postJson('/api/checkout', $this->payload($game, $package, [
            'customer_email' => 'no-member@example.com',
            'idempotency_key' => (string) Str::uuid(),
        ]));
        $standardResponse->assertCreated();
        $standardOrder = Order::query()->where('customer_email', 'no-member@example.com')->firstOrFail();

        $this->assertSame('standard', $standardOrder->pricing_basis->value);
        $this->assertSame(1320, $standardOrder->selling_price); // 1200 + 10% reseller markup
        $this->assertSame(120, $standardOrder->reseller_profit); // round(1200 * 10%)
    }

    /**
     * ADR-027 Phase 6's confirmed fallback: insufficient quota never
     * blocks checkout — this order simply charges the standard price,
     * exactly as if no membership token had been sent, and the
     * member's quota is left untouched for their other orders this
     * cycle.
     */
    public function test_a_member_with_insufficient_quota_falls_back_to_the_standard_price(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'poor-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 500, // < the 1040 member price
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('poor-member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'poor-member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertNull($order->member_discount_percent);
        $this->assertNull($order->normal_selling_price);
        $this->assertSame(1200, $order->selling_price);
        $this->assertSame(200, $order->platform_profit); // 1200 - 1000

        $this->assertSame(500, $membership->fresh()->quota_remaining_sen); // untouched
        $this->assertSame(0, MembershipQuotaDebit::query()->count());
    }

    /**
     * No membership feature is a *gate* on checkout (base ADR decision
     * 10) — a garbage/expired token must degrade to exactly the same
     * standard-price behavior as sending no token at all, never a
     * validation error.
     */
    /**
     * ADR-027 decision 20: the kill switch (seeded off pre-launch)
     * gates checkout-time member pricing exactly like it already gates
     * CatalogController's member_price_sen — a still-valid session
     * token from earlier testing must never silently apply member
     * pricing while the feature is meant to be fully invisible.
     */
    public function test_a_member_checks_out_at_the_standard_price_when_the_membership_feature_is_disabled(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => false]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertSame(1200, $order->selling_price);
    }

    public function test_an_invalid_membership_token_checks_out_at_the_standard_price_like_a_guest(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package),
            ['Authorization' => 'Bearer garbage'],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertSame(1200, $order->selling_price);
    }

    /**
     * Bug fix, 2026-08-30 — CheckoutController::previewTotal(). The
     * storefront's pre-payment total never included the transaction
     * fee before this; these tests lock the new preview endpoint's
     * numbers directly against the real /api/checkout charge for the
     * same inputs, so the two can never quietly drift apart again.
     */
    public function test_preview_totals_matches_a_standard_order_with_no_voucher_or_member(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500, flat_fee_sen = 210

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ]);

        $preview->assertOk();
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 0,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 710,
        ]);

        // Parity check against the real charge for the identical inputs.
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        $order = Order::query()->firstOrFail();
        $this->assertSame(710, $order->final_amount);
    }

    public function test_preview_totals_reflects_a_partial_cover_voucher_same_as_the_real_charge(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]);

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $preview->assertOk();
        // feeBase = 500 - 200 = 300; flat_fee_sen = 210 -> matches
        // test_partial_cover_voucher_reduces_fee_base_and_redeems_after_gateway_success()'s real-order numbers exactly.
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 200,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 510,
        ]);
    }

    public function test_preview_totals_reflects_a_full_cover_voucher_skipping_the_flat_fee(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 1000]);

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $preview->assertOk();
        // ADR-024 decision #5: feeBase 0 -> transaction_fee/final_amount forced to 0, not the flat_fee_sen=210.
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 500,
            'transaction_fee_sen' => 0,
            'final_amount_sen' => 0,
        ]);
    }

    public function test_preview_totals_uses_the_authenticated_members_own_price(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage(); // cost 1000, standard 1200, Tier 2 -> member price 1040
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 5000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $preview = $this->postJson(
            '/api/checkout/preview-totals',
            ['game_id' => $game->id, 'package_id' => $package->id, 'channel_code' => 'FPX_ABMB'],
            ['Authorization' => "Bearer {$token}"],
        );

        $preview->assertOk();
        $preview->assertExactJson([
            'selling_price_sen' => 1040,
            'member_discount_percent' => 80.0,
            'voucher_discount_sen' => 0,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 1250,
        ]);
    }

    public function test_preview_totals_rejects_an_inactive_package(): void
    {
        $this->activeChannel();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([], ['is_active' => false]);

        $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ])->assertStatus(422);
    }
}
