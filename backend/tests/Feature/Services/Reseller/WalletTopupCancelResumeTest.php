<?php

namespace Tests\Feature\Services\Reseller;

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
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupCancelOutcome;
use App\Services\Reseller\WalletTopupEndCause;
use App\Services\Reseller\WalletTopupNotPayableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-135 decisions 1, 2, 5: reading, resuming and cancelling a pending
 * Purchase Balance top-up. A cancel asks CHIP to cancel, then acts only on
 * CHIP's re-read answer; it never credits.
 */
class WalletTopupCancelResumeTest extends TestCase
{
    use RefreshDatabase;

    /** What CHIP's getPayment() says after the cancel: 'cancelled', 'expired', 'paid', 'pending', 'down'. */
    public string $chipSays = 'cancelled';

    /** @var list<string> */
    public array $cancelCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'Online Banking (FPX)', 'category' => 'fpx',
            'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);

        $test = $this;
        $this->app->bind('payment-gateway.chip', fn () => new class($test) implements PaymentGateway
        {
            public function __construct(private readonly WalletTopupCancelResumeTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return match ($this->test->chipSays) {
                    'down' => PaymentResponse::failure('503', 'CHIP down'),
                    'paid' => PaymentResponse::success(['status' => 'paid', 'amount_sen' => 10100], status: PaymentStatus::Paid),
                    'pending' => PaymentResponse::success(['status' => 'pending_execute'], status: PaymentStatus::Pending),
                    default => PaymentResponse::success(
                        ['status' => $this->test->chipSays, 'failure_is_final' => true, 'ended_by_expiry' => $this->test->chipSays === 'expired'],
                        status: PaymentStatus::Failed,
                    ),
                };
            }

            public function cancelPayment(string $paymentRequestId): PaymentResponse
            {
                $this->test->cancelCalls[] = $paymentRequestId;

                return PaymentResponse::failure('400', 'whatever CHIP says is not read');
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

    private function reseller(string $name = 'Cancel Reseller'): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => $name, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function attempt(Reseller $reseller, array $overrides = []): WalletTopupAttempt
    {
        return WalletTopupAttempt::query()->create($overrides + [
            'reseller_id' => $reseller->id,
            'reference' => 'WT-CANCEL0001',
            'amount_sen' => 10000,
            'total_charged_sen' => 10100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'chip_payment_ref' => 'chip-1',
            'checkout_url' => 'https://gate.chip-in.asia/p/chip-1/',
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function service(): ResellerWalletTopupService
    {
        return app(ResellerWalletTopupService::class);
    }

    private function cancel(Reseller $reseller, string $reference = 'WT-CANCEL0001'): WalletTopupCancelOutcome
    {
        return $this->service()->cancel($reseller, $reference, WalletTopupEndCause::PortalCancel);
    }

    // --- decision 1: current pending is a database read ---

    public function test_current_pending_returns_the_live_pending_row_with_or_without_a_link(): void
    {
        $reseller = $this->reseller();
        $this->assertNull($this->service()->currentPending($reseller));

        $attempt = $this->attempt($reseller, ['chip_payment_ref' => null, 'checkout_url' => null]);

        $this->assertSame($attempt->id, $this->service()->currentPending($reseller)?->id);
        $this->assertSame([], $this->cancelCalls);
    }

    public function test_current_pending_ignores_an_ended_or_timed_out_row(): void
    {
        $reseller = $this->reseller();
        $this->attempt($reseller, ['expires_at' => now()->subMinute()]);
        $this->attempt($reseller, ['reference' => 'WT-FAILED0001', 'status' => WalletTopupAttemptStatus::Failed->value]);

        $this->assertNull($this->service()->currentPending($reseller));
    }

    // --- decision 5: cancel is decided by CHIP's re-read answer ---

    public function test_a_cancel_chip_confirms_ends_the_attempt(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);

        $this->assertSame(WalletTopupCancelOutcome::Cancelled, $this->cancel($reseller));

        $this->assertSame(['chip-1'], $this->cancelCalls);
        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertNull($this->service()->currentPending($reseller));
    }

    public function test_a_purchase_that_ran_out_of_time_is_named_expired(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);
        $this->chipSays = 'expired';

        $this->assertSame(WalletTopupCancelOutcome::Cancelled, $this->cancel($reseller));
        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->fresh()->status);
    }

    /** The cancel path never credits: the webhook or reconcile will. */
    public function test_a_purchase_already_paid_is_left_alone(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);
        $this->chipSays = 'paid';

        $this->assertSame(WalletTopupCancelOutcome::AlreadyPaid, $this->cancel($reseller));

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(0, app(ResellerWalletService::class)->balance($reseller));
    }

    public function test_a_payment_in_flight_at_the_bank_is_refused(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);
        $this->chipSays = 'pending';

        $this->assertSame(WalletTopupCancelOutcome::InFlight, $this->cancel($reseller));
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
    }

    public function test_chip_unreachable_is_refused_and_the_attempt_stays_pending(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);
        $this->chipSays = 'down';

        $this->assertSame(WalletTopupCancelOutcome::InFlight, $this->cancel($reseller));
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
    }

    public function test_an_attempt_with_no_chip_purchase_yet_cannot_be_cancelled(): void
    {
        $reseller = $this->reseller();
        $this->attempt($reseller, ['chip_payment_ref' => null, 'checkout_url' => null]);

        $this->assertSame(WalletTopupCancelOutcome::NoPurchaseYet, $this->cancel($reseller));
        $this->assertSame([], $this->cancelCalls);
    }

    /** A webhook's Paid (or another cancel) committed first: report what the row really is. */
    public function test_an_attempt_that_already_left_pending_reports_its_real_state(): void
    {
        $reseller = $this->reseller();
        $this->attempt($reseller, ['status' => WalletTopupAttemptStatus::Paid->value]);
        $this->assertSame(WalletTopupCancelOutcome::AlreadyPaid, $this->cancel($reseller));

        $other = $this->reseller('Other');
        $this->attempt($other, ['reference' => 'WT-ENDED00002', 'status' => WalletTopupAttemptStatus::Expired->value]);
        $this->assertSame(WalletTopupCancelOutcome::AlreadyEnded, $this->cancel($other, 'WT-ENDED00002'));

        $this->assertSame([], $this->cancelCalls);
    }

    public function test_another_resellers_top_up_is_not_found(): void
    {
        $owner = $this->reseller();
        $this->attempt($owner);

        $this->expectException(ModelNotFoundException::class);
        $this->cancel($this->reseller('Stranger'));
    }

    // --- decision 2: resume verifies only when asked ---

    public function test_resume_hands_back_the_link_while_chip_still_takes_payment(): void
    {
        $reseller = $this->reseller();
        $this->attempt($reseller);
        $this->chipSays = 'pending';

        $this->assertSame('https://gate.chip-in.asia/p/chip-1/', $this->service()->resume($reseller, 'WT-CANCEL0001'));
    }

    /** ADR-110 2026-10-29: a dead link is never handed out. */
    public function test_resume_ends_an_attempt_chip_has_ended_and_refuses(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->attempt($reseller);
        $this->chipSays = 'cancelled';

        try {
            $this->service()->resume($reseller, 'WT-CANCEL0001');
            $this->fail('A dead link was handed out.');
        } catch (WalletTopupNotPayableException) {
            $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        }
    }

    public function test_resume_refuses_an_attempt_with_no_link_yet(): void
    {
        $reseller = $this->reseller();
        $this->attempt($reseller, ['chip_payment_ref' => null, 'checkout_url' => null]);

        $this->expectException(WalletTopupNotPayableException::class);
        $this->service()->resume($reseller, 'WT-CANCEL0001');
    }
}
