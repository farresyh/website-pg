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
 * gateway webhook never arrived. Reconciliation is terminal-status-driven
 * (the gateway's own answer of paid / failed), not time-driven — see the
 * command's own docblock.
 */
class ReconcilePendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_gateway' => 'chip',
            'payment_ref' => 'chip_purchase_ref_'.uniqid(),
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
    private function bindFakeGateway(string $status, string $gateway = 'chip'): void
    {
        $this->app->bind("payment-gateway.{$gateway}", fn () => new class($status) implements PaymentGateway
        {
            public function __construct(private readonly string $status) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            /**
             * Mirrors what a real PaymentGateway::getPayment() must do
             * (ADR-022's 2026-08-03 addendum, found while building
             * ChipGateway): translate the gateway's own raw status
             * string into the typed PaymentStatus enum here, inside the
             * fake gateway itself — never leave that to the caller.
             * Status strings match ChipGateway's real mapping.
             */
            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                $status = match ($this->status) {
                    'paid' => PaymentStatus::Paid,
                    'error', 'cancelled' => PaymentStatus::Failed,
                    default => PaymentStatus::Pending,
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
        $this->bindFakeGateway('paid');
        $order = $this->stalePending(['order_number' => 'KRS-RECOVER']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Bus::assertDispatched(FulfillOrderJob::class, fn ($job) => $job->order->id === $order->id);
    }

    public function test_does_not_redispatch_fulfillment_for_an_order_already_paid(): void
    {
        Bus::fake();
        $this->bindFakeGateway('paid');
        // Shouldn't happen given the query only selects Pending orders,
        // but recover() itself guards it (mirrors the webhook controller's
        // own PAY-2 duplicate-delivery guard) — worth proving directly.
        $order = $this->stalePending(['order_number' => 'KRS-ALREADY-PAID', 'payment_status' => PaymentStatus::Paid->value]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    private function assertMarksOrderFailedOnTerminalFailure(string $chipStatus): void
    {
        Bus::fake();
        $this->bindFakeGateway($chipStatus);
        $order = $this->stalePending(['order_number' => 'KRS-FAILED-'.$chipStatus]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_marks_an_order_failed_when_the_gateway_reports_error(): void
    {
        $this->assertMarksOrderFailedOnTerminalFailure('error');
    }

    public function test_marks_an_order_failed_when_the_gateway_reports_cancelled(): void
    {
        $this->assertMarksOrderFailedOnTerminalFailure('cancelled');
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
        $this->bindFakeGateway('error');

        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
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
        $this->app->bind('payment-gateway.chip', fn () => new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['status' => 'paid', 'amount_sen' => 1], status: PaymentStatus::Paid);
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
        $this->bindFakeGateway('hold');
        $order = $this->stalePending(['order_number' => 'KRS-AMBIGUOUS-YOUNG']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_leaves_an_ambiguous_status_untouched_even_past_the_flag_window(): void
    {
        Bus::fake();
        $this->bindFakeGateway('pending_execute');
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
        $this->bindFakeGateway('paid');
        $order = $this->stalePending(['order_number' => 'KRS-NO-PAYMENT-REF', 'payment_ref' => null]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_ignores_an_order_that_is_not_yet_stale(): void
    {
        Bus::fake();
        $this->bindFakeGateway('paid');
        $order = $this->order(['order_number' => 'KRS-FRESH']); // created just now, well within the 30-minute grace window

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    /**
     * ADR-022's 2026-08-03 addendum, decision 1 — an order is asked its
     * OWN recorded gateway, resolved through PaymentGatewayFactory per
     * row, never one single container-default binding. CHIP is the only
     * real gateway today (2026-09-01 addendum), so a second name is
     * bound ad-hoc here purely to prove the per-order routing mechanism
     * the seam keeps alive for a future multi-gateway world — before
     * this fix every order went through one fixed binding regardless of
     * its `payment_gateway`.
     */
    public function test_resolves_each_orders_own_recorded_gateway_instead_of_one_fixed_gateway(): void
    {
        Bus::fake();
        $this->bindFakeGateway('paid', 'chip');
        $this->bindFakeGateway('error', 'legacy-processor');

        $chipOrder = $this->stalePending(['order_number' => 'KRS-CHIP', 'payment_gateway' => 'chip']);
        $legacyOrder = $this->stalePending(['order_number' => 'KRS-LEGACY', 'payment_gateway' => 'legacy-processor']);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $chipOrder->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Failed, $legacyOrder->fresh()->payment_status);
        Bus::assertDispatched(FulfillOrderJob::class, fn ($job) => $job->order->id === $chipOrder->id);
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
        // Proves the gateway is never even called for this order — even
        // with a working 'chip' binding present, a NULL payment_gateway
        // is skipped, not guessed.
        $this->bindFakeGateway('paid', 'chip');
        $order = $this->stalePending(['order_number' => 'KRS-NO-GATEWAY', 'payment_gateway' => null]);

        $this->artisan('app:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Bus::assertNotDispatched(FulfillOrderJob::class);
        Log::shouldHaveReceived('error')->once();
    }
}
