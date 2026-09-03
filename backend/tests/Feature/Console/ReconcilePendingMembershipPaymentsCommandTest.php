<?php

namespace Tests\Feature\Console;

use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\PaymentMethod;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-068 decision 10 — ReconcilePendingMembershipPaymentsCommand
 * catches a self-serve subscription whose CHIP webhook never arrived.
 * Terminal-status-driven, same as ReconcilePendingPaymentsCommand.
 */
class ReconcilePendingMembershipPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryReseller();
        Queue::fake();
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);
    }

    private function stalePending(array $overrides = []): MembershipCheckoutAttempt
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        $attempt = MembershipCheckoutAttempt::query()->create(array_merge([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'payment_ref' => 'chip-purchase-'.uniqid(),
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ], $overrides));

        $attempt->forceFill(['created_at' => now()->subMinutes(45)])->save();

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

    public function test_a_paid_gateway_status_activates_the_membership(): void
    {
        $attempt = $this->stalePending();
        $this->bindGateway('paid', $attempt->total_charged_sen);

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(1, Membership::query()->where('email', 'member@example.com')->count());
    }

    public function test_an_amount_mismatch_refuses_to_activate(): void
    {
        $this->stalePending();
        $this->bindGateway('paid', 1);

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, MembershipCheckoutAttempt::query()->firstOrFail()->status);
        $this->assertSame(0, Membership::query()->count());
    }

    public function test_a_failed_gateway_status_marks_the_attempt_failed(): void
    {
        $this->stalePending();
        $this->bindGateway('error', null);

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Failed, MembershipCheckoutAttempt::query()->firstOrFail()->status);
    }

    public function test_a_still_pending_attempt_past_the_expiry_window_is_expired(): void
    {
        $attempt = $this->stalePending();
        $attempt->forceFill(['created_at' => now()->subHours(30)])->save();
        $this->bindGateway('pending', null);

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_an_attempt_with_no_payment_ref_is_expired_once_stale(): void
    {
        $attempt = $this->stalePending(['payment_ref' => null]);
        $attempt->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_a_fresh_pending_attempt_is_left_alone(): void
    {
        $attempt = $this->stalePending();
        $attempt->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $this->bindGateway('pending', null);

        $this->artisan('app:reconcile-pending-membership-payments')->assertSuccessful();

        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, $attempt->fresh()->status);
    }
}
