<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\InvalidWalletTopupAmountException;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\PendingWalletTopupAlreadyExistsException;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupCheckoutFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-073 decision 3(a) / PR-G: the self-serve CHIP wallet top-up flow.
 */
class ResellerWalletTopupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeFpx();
    }

    private function reseller(bool $withTier = true): Reseller
    {
        $tierId = $withTier
            ? ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1])->id
            : null;
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true, 'reseller_tier_id' => $tierId]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

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

    public function test_initiate_rejects_an_amount_below_the_minimum(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();

        $this->expectException(InvalidWalletTopupAmountException::class);
        app(ResellerWalletTopupService::class)->initiate($reseller, 999, 'fpx', []);
    }

    public function test_initiate_creates_a_pending_attempt_with_a_fee_inclusive_charge_total(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();

        $attempt = app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);

        $this->assertSame(5000, $attempt->amount_sen);
        $this->assertSame(5100, $attempt->total_charged_sen); // flat fpx fee added on top
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->status);
        $this->assertNotNull($attempt->checkout_url);
        $this->assertStringStartsWith('WT-', $attempt->reference);
    }

    public function test_initiate_rejects_a_second_attempt_while_one_is_already_pending(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);

        $this->expectException(PendingWalletTopupAlreadyExistsException::class);
        app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);
    }

    public function test_initiate_allows_a_new_attempt_once_the_previous_one_expired(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        $first = app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);
        $first->update(['expires_at' => now()->subMinute()]);

        $second = app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, WalletTopupAttempt::query()->count());
    }

    public function test_initiate_marks_the_attempt_failed_when_the_gateway_call_fails(): void
    {
        $this->bindGateway(succeeds: false);
        $reseller = $this->reseller();

        $this->expectException(WalletTopupCheckoutFailedException::class);
        try {
            app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);
        } finally {
            $this->assertSame(WalletTopupAttemptStatus::Failed, WalletTopupAttempt::query()->firstOrFail()->status);
        }
    }

    /** ADR-133 decision 20: a reseller with no tier cannot order, so it cannot fund the account either. */
    public function test_initiate_refuses_a_reseller_with_no_tier_before_any_gateway_call(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller(withTier: false);

        try {
            app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);
            $this->fail('A tier-less reseller started a top-up.');
        } catch (NoResellerTierAssignedException) {
            $this->assertSame(0, WalletTopupAttempt::query()->count());
        }
    }

    /** ADR-133 decision 20: the guard is at the start only; a top-up already paid always credits. */
    public function test_a_pending_top_up_still_credits_after_the_tier_is_removed(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        $attempt = app(ResellerWalletTopupService::class)->initiate($reseller, 5000, 'fpx', []);

        Reseller::query()->whereKey($reseller->id)->update(['reseller_tier_id' => null]);
        app(ResellerWalletService::class)->completeTopup($attempt->fresh(), 100);

        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(5000, app(ResellerWalletService::class)->balance($reseller->fresh()));
    }
}
