<?php

namespace Tests\Feature\Console;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-021 (PAY-3) — ReconcilePendingPaymentsCommand catches orders whose
 * Xendit webhook never arrived. Reconciliation is terminal-status-driven
 * (SUCCEEDED/EXPIRED/FAILED/CANCELED), not time-driven — see the command's
 * own docblock.
 */
class ReconcilePendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_ref' => 'xnd_payment_ref_'.uniqid(),
        ], $overrides));
    }

    private function stalePending(array $overrides = []): Order
    {
        $order = $this->order($overrides);
        $order->forceFill(['created_at' => now()->subMinutes(45)])->save();

        return $order->fresh();
    }

    private function bindFakeGateway(string $status): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class($status) implements PaymentGateway
        {
            public function __construct(private readonly string $status) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['status' => $this->status]);
            }

            public function verifyWebhookSignature(string $providedToken): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        });
    }

    public function test_recovers_an_order_whose_payment_actually_succeeded(): void
    {
        Bus::fake();
        $this->bindFakeGateway('SUCCEEDED');
        $order = $this->stalePending(['order_number' => 'KRS-RECOVER']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Bus::assertDispatched(FulfillOrderJob::class, fn ($job) => $job->order->id === $order->id);
    }

    public function test_does_not_redispatch_fulfillment_for_an_order_already_paid(): void
    {
        Bus::fake();
        $this->bindFakeGateway('SUCCEEDED');
        // Shouldn't happen given the query only selects Pending orders,
        // but recover() itself guards it (mirrors XenditWebhookController's
        // own PAY-2 duplicate-delivery guard) — worth proving directly.
        $order = $this->stalePending(['order_number' => 'KRS-ALREADY-PAID', 'payment_status' => PaymentStatus::Paid->value]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    private function assertMarksOrderFailedOnTerminalFailure(string $xenditStatus): void
    {
        Bus::fake();
        $this->bindFakeGateway($xenditStatus);
        $order = $this->stalePending(['order_number' => 'KRS-FAILED-'.$xenditStatus]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_marks_an_order_failed_when_the_gateway_reports_expired(): void
    {
        $this->assertMarksOrderFailedOnTerminalFailure('EXPIRED');
    }

    public function test_marks_an_order_failed_when_the_gateway_reports_failed(): void
    {
        $this->assertMarksOrderFailedOnTerminalFailure('FAILED');
    }

    public function test_marks_an_order_failed_when_the_gateway_reports_canceled(): void
    {
        $this->assertMarksOrderFailedOnTerminalFailure('CANCELED');
    }

    public function test_leaves_an_ambiguous_status_untouched_when_still_within_the_flag_window(): void
    {
        Bus::fake();
        $this->bindFakeGateway('AUTHORIZED');
        $order = $this->stalePending(['order_number' => 'KRS-AMBIGUOUS-YOUNG']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_leaves_an_ambiguous_status_untouched_even_past_the_flag_window(): void
    {
        Bus::fake();
        $this->bindFakeGateway('REQUIRES_ACTION');
        $order = $this->order(['order_number' => 'KRS-AMBIGUOUS-OLD']);
        $order->forceFill(['created_at' => now()->subHours(25)])->save();

        // Flagging for review is a log-only signal (visible via the
        // /admin/orders awaiting_payment filter instead) — never an
        // auto-decided state change, per ADR-021's explicit rejection of
        // "24h elapsed => auto-fail".
        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_skips_an_order_with_no_payment_ref(): void
    {
        Bus::fake();
        $this->bindFakeGateway('SUCCEEDED');
        $order = $this->stalePending(['order_number' => 'KRS-NO-PAYMENT-REF', 'payment_ref' => null]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_ignores_an_order_that_is_not_yet_stale(): void
    {
        Bus::fake();
        $this->bindFakeGateway('SUCCEEDED');
        $order = $this->order(['order_number' => 'KRS-FRESH']); // created just now, well within the 30-minute grace window

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }
}
