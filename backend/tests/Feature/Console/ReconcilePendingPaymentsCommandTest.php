<?php

namespace Tests\Feature\Console;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
            'payment_gateway' => 'xendit',
            'payment_ref' => 'xnd_payment_ref_'.uniqid(),
        ], $overrides));
    }

    private function stalePending(array $overrides = []): Order
    {
        $order = $this->order($overrides);
        $order->forceFill(['created_at' => now()->subMinutes(45)])->save();

        return $order->fresh();
    }

    /**
     * Binds a fake behind the same `payment-gateway.<name>` container
     * key PaymentGatewayFactory::make() resolves through (see
     * CheckoutControllerTest's own bindGateway()) — not the plain
     * PaymentGateway::class default binding, since the command now
     * resolves per-order by the Order's own recorded payment_gateway
     * (ADR-022's newest addendum, decision 1) rather than a single
     * fixed gateway for every order.
     */
    private function bindFakeGateway(string $status, string $gateway = 'xendit'): void
    {
        $this->app->bind("payment-gateway.{$gateway}", fn () => new class($status) implements PaymentGateway
        {
            public function __construct(private readonly string $status) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            /**
             * Mirrors what a real PaymentGateway::getPayment() must now
             * do (ADR-022's newest addendum, found while building
             * ChipGateway): translate the gateway's own raw status
             * string into the typed PaymentStatus enum here, inside the
             * fake gateway itself — never leave that to the caller.
             */
            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                $status = match ($this->status) {
                    'SUCCEEDED' => \App\Services\Order\PaymentStatus::Paid,
                    'EXPIRED', 'FAILED', 'CANCELED' => \App\Services\Order\PaymentStatus::Failed,
                    default => \App\Services\Order\PaymentStatus::Pending,
                };

                // amount_sen fixed at 1100 to match order()'s own
                // default final_amount — every test in this file that
                // exercises the Paid/recover() branch relies on this
                // matching, same as real gateway getPayment() always
                // populating it from the gateway's own response.
                return PaymentResponse::success(['status' => $this->status, 'amount_sen' => 1100], status: $status);
            }

            public function verifyWebhookSignature(Request $request): bool
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

    /**
     * ADR-024 decision #6a — the second of the two real restore
     * triggers: a customer who reached the gateway page (locking the
     * voucher) but whose payment was never confirmed by a webhook at
     * all, only caught later by this stale-pending sweep.
     */
    public function test_restores_a_reserved_voucher_redemption_when_reconciliation_finds_a_terminal_failure(): void
    {
        Bus::fake();
        $this->bindFakeGateway('EXPIRED');

        $voucher = Voucher::query()->create([
            'code' => 'KRS-RECONCILE-VOUCHER',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 300,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->stalePending([
            'order_number' => 'KRS-RECONCILE-VOUCHER-ORDER',
            'voucher_id' => $voucher->id,
            'voucher_discount' => 200,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 200,
            'status' => 'reserved',
        ]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(500, $voucher->fresh()->remaining);
        $this->assertSame('restored', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    /**
     * Defense-in-depth: mirrors the webhook controllers' own amount
     * cross-check — a gateway reporting Paid with an amount that
     * doesn't match this order's final_amount is never recovered.
     */
    public function test_refuses_to_recover_when_the_gateway_reported_amount_does_not_match_the_order(): void
    {
        Bus::fake();
        Log::spy();
        $this->app->bind('payment-gateway.xendit', fn () => new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['status' => 'SUCCEEDED', 'amount_sen' => 1], status: PaymentStatus::Paid);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        });
        $order = $this->stalePending(['order_number' => 'KRS-AMOUNT-MISMATCH']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
        Log::shouldHaveReceived('error')->once();
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

    /**
     * ADR-022's newest addendum, decision 1 — the whole point of this
     * fix: two stale orders recorded against two different gateways
     * must each be asked their OWN gateway, never cross-routed. Before
     * this fix, every order was asked via one single container-default
     * PaymentGateway binding regardless of which gateway it actually
     * checked out with.
     */
    public function test_resolves_each_orders_own_recorded_gateway_instead_of_one_fixed_gateway(): void
    {
        Bus::fake();
        $this->bindFakeGateway('SUCCEEDED', 'xendit');
        $this->bindFakeGateway('FAILED', 'chip');

        $xenditOrder = $this->stalePending(['order_number' => 'KRS-XENDIT', 'payment_gateway' => 'xendit']);
        $chipOrder = $this->stalePending(['order_number' => 'KRS-CHIP', 'payment_gateway' => 'chip']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $xenditOrder->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Failed, $chipOrder->fresh()->payment_status);
        Bus::assertDispatched(FulfillOrderJob::class, fn ($job) => $job->order->id === $xenditOrder->id);
    }

    /**
     * Should be near-impossible after the Order-creation stamping fix
     * (CheckoutService::initiate() always sets payment_gateway) — but
     * if it ever happens, it signals a checkout bug, not routine
     * payment ambiguity, so it gets Log::error() (not flagIfStale()'s
     * Log::warning()) and is never guessed at a default gateway, which
     * would risk silently re-misrouting the order.
     */
    public function test_skips_and_logs_an_error_for_an_order_with_no_recorded_payment_gateway(): void
    {
        Log::spy();
        Bus::fake();
        // Proves the gateway is never even called for this order — a
        // fallback guess would still resolve to 'xendit' and succeed.
        $this->bindFakeGateway('SUCCEEDED', 'xendit');
        $order = $this->stalePending(['order_number' => 'KRS-NO-GATEWAY', 'payment_gateway' => null]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
        Log::shouldHaveReceived('error')->once();
    }
}
