<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-072 decision 5 / PR-G: the Reseller (wallet) portal's Wallet
 * screen — balance + ledger history + self-serve CHIP top-up.
 */
class WalletControllerTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Reseller $reseller): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Reseller Staff', 'email' => 'staff+'.$reseller->id.'@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function reseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function activeFpx(): void
    {
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'Online Banking (FPX)', 'category' => 'fpx',
            'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);
    }

    private function bindGateway(): void
    {
        $gateway = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => 'pr-'.$request->referenceId,
                    'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                ]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used');
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

    public function test_show_returns_the_wallet_balance_and_ledger_history(): void
    {
        $reseller = $this->reseller();
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'wallet_topup');
        $token = $this->tokenFor($reseller);

        $this->withToken($token)->getJson('/api/reseller-portal/wallet')
            ->assertOk()
            ->assertJsonPath('balance', 5000)
            ->assertJsonPath('entries.data.0.type', 'wallet_topup');
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/reseller-portal/wallet')->assertUnauthorized();
    }

    public function test_topup_creates_a_pending_attempt_and_returns_a_checkout_url(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);

        $response = $this->withToken($token)->postJson('/api/reseller-portal/wallet/topup', [
            'amount_sen' => 5000, 'channel_code' => 'fpx',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_sen', 5000)
            ->assertJsonPath('total_charged_sen', 5100);
        $this->assertStringContainsString('gate.chip-in.asia', $response->json('checkout_url'));
    }

    public function test_topup_rejects_an_amount_below_the_minimum(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $token = $this->tokenFor($this->reseller());

        $this->withToken($token)->postJson('/api/reseller-portal/wallet/topup', [
            'amount_sen' => 999, 'channel_code' => 'fpx',
        ])->assertUnprocessable();
    }

    public function test_topup_rejects_a_second_attempt_while_one_is_pending(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);

        $this->withToken($token)->postJson('/api/reseller-portal/wallet/topup', [
            'amount_sen' => 5000, 'channel_code' => 'fpx',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/reseller-portal/wallet/topup', [
            'amount_sen' => 5000, 'channel_code' => 'fpx',
        ])->assertUnprocessable();
    }
}
