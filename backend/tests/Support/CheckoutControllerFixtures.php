<?php

namespace Tests\Support;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Shared setup and helpers for the CheckoutControllerTest split (one file per feature). */
trait CheckoutControllerFixtures
{
    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: checkout resolves the platform's own storefront via
        // Affiliate::primary(), which fails loud when it is missing (no
        // more firstOrCreate). Every checkout path needs it present.
        $this->primaryAffiliate();
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
            'name' => 'Mobile Legends Validator',
            'key' => 'mlbb',
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

    private function voucher(array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
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
        return app(MembershipSessionTokenService::class)->issue($this->primaryAffiliate()->id, $email);
    }
}
