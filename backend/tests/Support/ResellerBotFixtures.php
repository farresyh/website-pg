<?php

namespace Tests\Support;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Models\Game;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Shared setup and helpers for the ResellerBotServiceTest split (one file per feature). */
trait ResellerBotFixtures
{
    private const GROUP_ID = 'g1@g.us';

    private function makePackage(int $costPrice = 1000, string $resellerCode = 'MLMY', bool $requiresServerId = true): Package
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create([
            'name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my-'.strtolower($resellerCode),
            'reseller_code' => $resellerCode, 'is_active' => true,
            'validation_rules' => $requiresServerId ? ['extra_field' => 'Zone ID'] : null,
        ]);

        return Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => $costPrice, 'standard_selling_price' => $costPrice + 200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
    }

    private function makeLinkedReseller(int $walletBalance = 10000, float $markupPercent = 10): Reseller
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $walletBalance, 'wallet_topup');
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => self::GROUP_ID, 'is_active' => true]);

        return $reseller;
    }

    private function activeFpx(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'channel_code' => 'fpx',
            'label' => 'Online Banking (FPX)',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 100,
        ]);
    }

    private function bindGateway(bool $succeeds = true): void
    {
        $gateway = new class($succeeds) implements PaymentGateway
        {
            public function __construct(private readonly bool $succeeds) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->succeeds
                    ? PaymentResponse::success([
                        'payment_request_id' => 'pr-'.$request->referenceId,
                        'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                    ])
                    : PaymentResponse::failure('API_ERROR', 'gateway said no');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId], status: null);
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

    private function makeZoneGame(string $resellerCode): Game
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('redxgame', 'Lumine', null, server: 'Asia', zone: 'Asia');
            }
        };
        $this->app->bind('player-validator.genshin', fn () => $fake);

        $game = $this->makePackage(resellerCode: $resellerCode)->game;
        $profile = PlayerValidatorProfile::query()->create(['name' => 'Genshin Validator', 'key' => 'genshin']);
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => $profile->id,
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['Asia', 'America', 'Europe']],
        ]);

        return $game;
    }

    // --- ADR-097 2026-10-05 addendum (decisions 27-32): per-game input contract ---

    /** @return list<string> every reply text the bot queued */
    private function replies(): array
    {
        $texts = [];
        Queue::assertPushed(SendResellerBotReplyJob::class, function (SendResellerBotReplyJob $job) use (&$texts) {
            $texts[] = $job->text;

            return true;
        });

        return $texts;
    }

    private function captureReplies(): void
    {
        config(['services.openwa.session_id' => 'session-1', 'services.openwa.api_key' => 'key-1']);
        Queue::fake();
    }
}
