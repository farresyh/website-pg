<?php

namespace Tests\Feature\Console;

use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * PR-G planning addendum decision 9 — ReconcilePendingWalletTopupsCommand
 * catches a self-serve wallet top-up whose CHIP webhook never arrived.
 * Mirrors ReconcilePendingMembershipPaymentsCommandTest's own shape
 * exactly, expiry driven by the attempt's own hard `expires_at` rather
 * than a separate expire-after-hours config.
 */
class ReconcilePendingWalletTopupsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);
    }

    private function reseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function stalePending(array $overrides = []): WalletTopupAttempt
    {
        $reseller = $overrides['reseller'] ?? $this->reseller();
        unset($overrides['reseller']);

        $attempt = WalletTopupAttempt::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-'.uniqid(),
            'amount_sen' => 5000,
            'total_charged_sen' => 5100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'chip_payment_ref' => 'chip-purchase-'.uniqid(),
            'expires_at' => now()->addMinutes(30),
        ], $overrides));

        $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

        return $attempt->fresh();
    }

    private function bindGateway(string $rawStatus, ?int $amountSen): void
    {
        $this->app->bind('payment-gateway.chip', fn () => new class($rawStatus, $amountSen) implements PaymentGateway
        {
            public function __construct(private readonly string $rawStatus, private readonly ?int $amountSen) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                $status = match ($this->rawStatus) {
                    'paid' => PaymentStatus::Paid,
                    'error' => PaymentStatus::Failed,
                    default => PaymentStatus::Pending,
                };

                $data = ['status' => $this->rawStatus];
                if ($this->amountSen !== null) {
                    $data['amount_sen'] = $this->amountSen;
                }

                return PaymentResponse::success($data, status: $status);
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

    public function test_a_paid_gateway_status_credits_the_wallet(): void
    {
        $attempt = $this->stalePending();
        $this->bindGateway('paid', $attempt->total_charged_sen);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(5000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id));
    }

    public function test_an_amount_mismatch_refuses_to_credit(): void
    {
        $attempt = $this->stalePending();
        $this->bindGateway('paid', 1);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id));
    }

    public function test_a_failed_gateway_status_marks_the_attempt_failed(): void
    {
        $attempt = $this->stalePending();
        $this->bindGateway('error', null);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
    }

    public function test_a_still_pending_attempt_past_its_hard_expiry_window_is_expired(): void
    {
        $attempt = $this->stalePending(['expires_at' => now()->subMinute()]);
        $this->bindGateway('pending', null);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_an_attempt_with_no_chip_payment_ref_is_expired_once_past_its_window(): void
    {
        $attempt = $this->stalePending(['chip_payment_ref' => null, 'expires_at' => now()->subMinute()]);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_a_still_pending_attempt_within_its_window_is_left_alone(): void
    {
        $attempt = $this->stalePending();
        $this->bindGateway('pending', null);

        $this->artisan('app:reconcile-pending-wallet-topups')->assertSuccessful();

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
    }
}
