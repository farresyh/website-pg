<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Order;
use App\Models\Voucher;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/**
 * ADR-110 2026-10-29 addendum: a failed attempt is final. The customer who
 * cancels at the bank starts a new checkout; the order fails at once (voucher
 * and quota given back) instead of 30–45 minutes later, and a link CHIP has
 * already ended is never handed out again.
 */
class CheckoutControllerFailedAttemptTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /** What the fake CHIP answers to getPayment(); null = still `created`. */
    public ?array $answer = null;

    public int $lookups = 0;

    private function bindProgrammableGateway(): void
    {
        $test = $this;
        $gateway = new class($test) implements PaymentGateway
        {
            public function __construct(private readonly CheckoutControllerFailedAttemptTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-failed-attempt']);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                $this->test->lookups++;
                $a = $this->test->answer ?? ['status' => PaymentStatus::Pending, 'failure_is_final' => false];

                return PaymentResponse::success([
                    'payment_request_id' => $paymentRequestId,
                    'amount_sen' => 710,
                    'failure_is_final' => $a['failure_is_final'],
                    'actions' => [['type' => 'REDIRECT', 'descriptor' => 'WEB_URL', 'value' => 'https://gate.chip-in.asia/p/pr-failed-attempt/']],
                ], status: $a['status']);
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
        $this->activeChannel('fpx');
    }

    private function cancelled(): void
    {
        $this->answer = ['status' => PaymentStatus::Failed, 'failure_is_final' => true];
    }

    private function retryableError(): void
    {
        $this->answer = ['status' => PaymentStatus::Failed, 'failure_is_final' => false];
    }

    private function pendingOrder(array $extra = []): Order
    {
        $this->bindProgrammableGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx', ...$extra]))->assertCreated();

        return Order::query()->firstOrFail();
    }

    public function test_the_status_page_check_fails_an_order_whose_purchase_cancelled(): void
    {
        $order = $this->pendingOrder();
        $this->cancelled();

        $response = $this->postJson("/api/track-order/{$order->order_number}/check-payment");

        $response->assertOk()->assertJsonPath('payment_status', 'failed');
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

    public function test_the_check_gives_the_voucher_back(): void
    {
        $voucher = $this->voucher(['amount' => 200, 'remaining' => 200]);
        $order = $this->pendingOrder(['voucher_code' => $voucher->code]);
        $this->assertSame(0, Voucher::query()->findOrFail($voucher->id)->remaining);
        $this->cancelled();

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")->assertOk();

        $this->assertSame(200, Voucher::query()->findOrFail($voucher->id)->remaining);
    }

    public function test_a_retryable_error_is_left_pending(): void
    {
        $order = $this->pendingOrder();
        $this->retryableError();

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")
            ->assertOk()->assertJsonPath('payment_status', 'pending');
    }

    public function test_a_purchase_still_open_at_chip_stays_pending(): void
    {
        $order = $this->pendingOrder();

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")
            ->assertOk()->assertJsonPath('payment_status', 'pending');
    }

    public function test_the_check_applies_a_paid_answer_the_webhook_has_not_delivered_yet(): void
    {
        Queue::fake();
        $order = $this->pendingOrder();
        $this->answer = ['status' => PaymentStatus::Paid, 'failure_is_final' => false];

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")
            ->assertOk()->assertJsonPath('payment_status', 'paid');
    }

    public function test_the_check_asks_chip_at_most_once_in_a_short_window(): void
    {
        $order = $this->pendingOrder();
        Cache::flush();
        $this->lookups = 0;

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")->assertOk();
        $this->postJson("/api/track-order/{$order->order_number}/check-payment")->assertOk();

        $this->assertSame(1, $this->lookups);
    }

    public function test_an_order_that_is_no_longer_pending_is_not_looked_up(): void
    {
        $order = $this->pendingOrder();
        $order->update(['payment_status' => PaymentStatus::Failed->value]);
        Cache::flush();
        $this->lookups = 0;

        $this->postJson("/api/track-order/{$order->order_number}/check-payment")
            ->assertOk()->assertJsonPath('payment_status', 'failed');

        $this->assertSame(0, $this->lookups);
    }

    public function test_an_unknown_order_is_a_404(): void
    {
        $this->bindProgrammableGateway();

        $this->postJson('/api/track-order/PG-NOPE/check-payment')->assertNotFound();
    }

    public function test_replaying_a_checkout_whose_purchase_cancelled_is_closed_not_given_the_dead_link(): void
    {
        $this->bindProgrammableGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, ['channel_code' => 'fpx']);
        $this->postJson('/api/checkout', $payload)->assertCreated();
        $this->cancelled();

        $replay = $this->postJson('/api/checkout', $payload);

        $replay->assertUnprocessable()->assertJsonPath('code', 'checkout_closed');
        $replay->assertJsonMissingPath('payment_actions');
        $this->assertSame(PaymentStatus::Failed, Order::query()->firstOrFail()->payment_status);
    }

    public function test_replaying_a_checkout_whose_purchase_is_still_open_returns_the_link(): void
    {
        $this->bindProgrammableGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, ['channel_code' => 'fpx']);
        $this->postJson('/api/checkout', $payload)->assertCreated();

        $replay = $this->postJson('/api/checkout', $payload);

        $replay->assertOk()->assertJsonPath('payment_actions.0.value', 'https://gate.chip-in.asia/p/pr-failed-attempt/');
    }
}
