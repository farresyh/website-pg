<?php

namespace Tests\Feature\Services\Checkout;

use App\Jobs\FulfillOrderJob;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Checkout\CheckoutAttemptClosedException;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\DuplicateCheckoutAttemptException;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderFactory;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Pricing\PricingService;
use App\Services\Voucher\VoucherService;
use App\Support\StorefrontBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CheckoutService
    {
        return new CheckoutService(
            new OrderPricingResolver(new PricingService, new MembershipPricingService(new PricingService)),
            new CheckoutTotalService,
            new OrderFactory(new OrderNumberService),
            new VoucherService(new LedgerService),
            new MembershipQuotaService,
            new StorefrontBrand,
        );
    }

    private function request(array $overrides = []): CheckoutRequest
    {
        return new CheckoutRequest(...array_merge([
            'customerEmail' => 'buyer@example.com',
            'customerName' => 'Buyer One',
            'customerPhone' => null,
            'playerId' => '123456',
            'serverId' => '1234',
            'costPriceSen' => 900,
            'standardSellingPriceSen' => 900,
            'packageMarkupPercent' => 0.0,
            'affiliateMarkupPct' => 0.0,
            'paymentFeeConfig' => new PaymentMethodFeeConfig(0.0, 100),
            'paymentMethod' => 'duitnow',
            'paymentGateway' => 'chip',
            'channelCode' => 'DUITNOW_PAY',
            'idempotencyKey' => (string) Str::uuid(),
            'supplierProductRef' => 'FFP5',
            'affiliateId' => $this->primaryAffiliate()->id,
        ], $overrides));
    }

    /**
     * $onCreate runs inside createPayment() — the window between the
     * unlocked preview and the locked reservation — so a test can
     * simulate a concurrent order spending the same balance there.
     */
    private function fakePaymentGateway(
        bool $success,
        ?array $data = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?\Closure $onCreate = null,
    ): PaymentGateway {
        return new class($success, $data, $errorCode, $errorMessage, $onCreate) implements PaymentGateway
        {
            public ?PaymentRequest $receivedRequest = null;

            public int $calls = 0;

            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
                private readonly ?\Closure $onCreate,
            ) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                $this->receivedRequest = $request;
                $this->calls++;

                if ($this->onCreate !== null) {
                    ($this->onCreate)();
                }

                return $this->success
                    ? PaymentResponse::success($this->data)
                    : PaymentResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        };
    }

    public function test_initiate_creates_a_pending_order_with_snapshotted_pricing(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request(), $gateway);

        $this->assertStringStartsWith('PG-', $order->order_number);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $order->delivery_status);
        $this->assertNull($order->reference_number); // ORD-8: not assigned until delivery starts
        $this->assertSame(1000, $order->final_amount); // 900 selling + 100 flat fee, no markup/voucher
        $this->assertSame('pr-123', $order->payment_ref);
    }

    /**
     * A payment-gateway failure leaves the Order in place (Pending, no
     * payment_ref) rather than rolling it back — that's the safe
     * failure direction. Order creation and the gateway call are
     * deliberately not wrapped in one transaction: if they were, a
     * commit failure after a successful gateway call could instead
     * orphan a real, payable CHIP purchase link with no matching
     * Order anywhere in the system, which is worse.
     */
    public function test_initiate_keeps_the_order_when_payment_request_creation_fails(): void
    {
        $gateway = $this->fakePaymentGateway(false, null, 'API_VALIDATION_ERROR', 'bad channel_properties');

        try {
            $this->service()->initiate($this->request(), $gateway);
            $this->fail('Expected CheckoutFailedException was not thrown.');
        } catch (CheckoutFailedException) {
            // expected
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->first()->payment_ref);
    }

    /**
     * ADR-022's newest addendum, decision 1: the Order snapshots which
     * gateway/channel it checked out with so ReconcilePendingPaymentsCommand
     * can resolve the correct PaymentGateway per order once a second
     * gateway exists — never a live-follow of the payment_methods row
     * (that row is mutable admin config, the Order's own history must not
     * silently change if it's later edited).
     */
    /**
     * Item 39 (2026-09-27 money-critical audit): the CHIP purchase
     * description is a line-item name shown on the checkout page/receipt —
     * the order_number is already carried separately as `reference`, so
     * this should describe what's being bought instead of repeating it.
     */
    public function test_initiate_sends_the_package_name_as_the_payment_description(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '278 Diamonds', 'denomination' => 278,
            'cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 0,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'FFP5',
        ]);
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $this->service()->initiate($this->request(['gameId' => $game->id, 'packageId' => $package->id]), $gateway);

        $this->assertSame('278 Diamonds', $gateway->receivedRequest->description);
    }

    public function test_initiate_stamps_the_payment_gateway_and_channel_code_onto_the_order(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request([
            'paymentGateway' => 'chip',
            'channelCode' => 'CHIP_FPX_B2C',
        ]), $gateway);

        $this->assertSame('chip', $order->payment_gateway);
        $this->assertSame('CHIP_FPX_B2C', $order->channel_code);
    }

    public function test_initiate_stamps_the_checkout_idempotency_key_onto_the_order(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request(['idempotencyKey' => 'idem-abc']), $gateway);

        $this->assertSame('idem-abc', $order->checkout_idempotency_key);
    }

    /**
     * ADR-019's checkout-level idempotency fix: the unique constraint
     * on checkout_idempotency_key is what actually closes the race a
     * plain app-level SELECT-then-INSERT would miss — a second
     * initiate() call with a key that's already taken must never reach
     * the gateway a second time.
     */
    public function test_initiate_throws_duplicate_checkout_attempt_when_the_key_already_exists(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);
        $this->service()->initiate($this->request(['idempotencyKey' => 'idem-dup']), $gateway);

        try {
            $this->service()->initiate($this->request(['idempotencyKey' => 'idem-dup']), $gateway);
            $this->fail('Expected DuplicateCheckoutAttemptException was not thrown.');
        } catch (DuplicateCheckoutAttemptException) {
            // expected
        }

        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The resume() path CheckoutController reaches when a retried
     * request finds an Order tagged with its key but no payment_ref
     * yet (the earlier attempt's gateway call failed). Reuses the
     * Order's own already-snapshotted final_amount/customer fields —
     * never recomputes pricing, matching ORD-9.
     */
    public function test_resume_retries_payment_for_an_existing_unpaid_order_without_recomputing_pricing(): void
    {
        $failingGateway = $this->fakePaymentGateway(false, null, 'API_VALIDATION_ERROR', 'bad channel_properties');

        try {
            $this->service()->initiate($this->request(['idempotencyKey' => 'idem-resume']), $failingGateway);
            $this->fail('Expected CheckoutFailedException was not thrown.');
        } catch (CheckoutFailedException) {
            // expected
        }

        $order = Order::query()->firstOrFail();
        $this->assertNull($order->payment_ref);
        $originalFinalAmount = $order->final_amount;

        $succeedingGateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-resumed']);
        $resumed = $this->service()->resume($order, $succeedingGateway, 'DUITNOW_PAY');

        $this->assertSame('pr-resumed', $resumed->payment_ref);
        $this->assertSame($originalFinalAmount, $resumed->final_amount);
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The storefront can't know order_number when it builds the checkout
     * request (the Order doesn't exist yet), so it sends a generic
     * fallback return URL. Once the Order exists, requestPayment() must
     * overwrite it with the real per-order tracking page — otherwise a
     * redirect-based channel (FPX, some e-wallets) sends the customer
     * back to the general order-lookup page instead of their own order.
     */
    public function test_initiate_overwrites_the_return_urls_with_the_orders_own_status_page(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request([
            'channelProperties' => [
                'success_return_url' => 'http://localhost:3001/track-order',
                'failure_return_url' => 'http://localhost:3001/track-order',
            ],
        ]), $gateway);

        $expectedUrl = rtrim((string) config('services.storefront.url'), '/')
            .'/order/status/'.$order->order_number;

        $this->assertSame($expectedUrl, $gateway->receivedRequest->channelProperties['success_return_url']);
        $this->assertSame($expectedUrl, $gateway->receivedRequest->channelProperties['failure_return_url']);
    }

    private function voucher(int $remaining, string $code = 'KRS-RACE'): Voucher
    {
        return Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => $code,
            'customer_email' => 'buyer@example.com',
            'amount' => $remaining,
            'remaining' => $remaining,
            'status' => 'active',
            'reason' => 'test',
        ]);
    }

    private function membership(int $quotaSen): Membership
    {
        return Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'buyer@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $quotaSen,
            'expires_at' => now()->addDays(20),
        ]);
    }

    /** A member-priced request: cost 1000, standard 1200 (20% markup). */
    private function memberRequest(int $membershipId, array $overrides = []): CheckoutRequest
    {
        return $this->request(array_merge([
            'costPriceSen' => 1000,
            'standardSellingPriceSen' => 1200,
            'packageMarkupPercent' => 20.0,
            'membershipId' => $membershipId,
        ], $overrides));
    }

    /**
     * ADR-024 2026-10-04 addendum, decision 1: a concurrent order drains
     * the voucher between the unlocked preview and the locked reserve.
     * The link must never be handed out, the order must be Failed.
     */
    public function test_a_partial_cover_checkout_that_loses_the_voucher_race_fails_closed(): void
    {
        $voucher = $this->voucher(500);
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-race'], onCreate: fn () => $voucher->update(['remaining' => 0, 'status' => 'exhausted']));

        try {
            $this->service()->initiate($this->request(['voucherCode' => 'KRS-RACE']), $gateway);
            $this->fail('Expected CheckoutAttemptClosedException was not thrown.');
        } catch (CheckoutAttemptClosedException) {
            // expected
        }

        $order = Order::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame(500, $order->voucher_discount, 'the snapshot stays as priced (ORD-9)');
        $this->assertSame(0, VoucherRedemption::query()->count());
        $this->assertSame(0, $voucher->fresh()->remaining, 'nothing of the other order\'s spend is touched');
    }

    /** Decision 3: the voucher won, the quota lost — the voucher goes back. */
    public function test_a_member_checkout_that_loses_the_quota_race_restores_its_voucher_and_fails_closed(): void
    {
        $voucher = $this->voucher(200);
        $membership = $this->membership(5000);
        VoucherRedemption::created(fn () => $membership->update(['quota_remaining_sen' => 0]));
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-race']);

        try {
            $this->service()->initiate($this->memberRequest($membership->id, ['voucherCode' => 'KRS-RACE']), $gateway);
            $this->fail('Expected CheckoutAttemptClosedException was not thrown.');
        } catch (CheckoutAttemptClosedException) {
            // expected
        }

        $order = Order::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame($membership->id, $order->membership_id);
        $this->assertSame(200, $voucher->fresh()->remaining);
        $this->assertSame('restored', VoucherRedemption::query()->firstOrFail()->status);
        $this->assertSame(0, MembershipQuotaDebit::query()->count());
    }

    /** Decision 4: full-cover decrements quota before Paid, and fails closed the same way. */
    public function test_a_full_cover_member_checkout_that_loses_the_quota_race_restores_the_voucher_and_fails_closed(): void
    {
        Queue::fake();
        $voucher = $this->voucher(5000);
        $membership = $this->membership(5000);
        VoucherRedemption::created(fn () => $membership->update(['quota_remaining_sen' => 0]));
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-unused']);

        try {
            $this->service()->initiate($this->memberRequest($membership->id, ['voucherCode' => 'KRS-RACE']), $gateway);
            $this->fail('Expected CheckoutAttemptClosedException was not thrown.');
        } catch (CheckoutAttemptClosedException) {
            // expected
        }

        $order = Order::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertSame(5000, $voucher->fresh()->remaining);
        $this->assertSame(0, $gateway->calls);
        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    /** §16 item 70: no gateway ran, so the order must not claim the checkout's channel. */
    public function test_a_full_cover_order_records_voucher_as_its_payment_method(): void
    {
        Queue::fake();
        $this->voucher(5000);

        $full = $this->service()->initiate($this->request(['voucherCode' => 'KRS-RACE']), $this->fakePaymentGateway(true));
        $partial = $this->service()->initiate($this->request(), $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-1']));

        $this->assertSame(0, $full->final_amount);
        $this->assertSame('voucher', $full->payment_method);
        $this->assertSame('duitnow', $partial->payment_method);
    }

    /** Decision 6: a replay of a Failed order never gets a link. */
    public function test_resume_refuses_an_order_that_already_failed(): void
    {
        $order = $this->service()->initiate($this->request(), $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-1']));
        $order->update(['payment_status' => PaymentStatus::Failed->value]);
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-2']);

        $this->expectException(CheckoutAttemptClosedException::class);

        try {
            $this->service()->resume($order, $gateway, 'DUITNOW_PAY');
        } finally {
            $this->assertSame(0, $gateway->calls);
        }
    }

    /**
     * Decision 6: a Pending order whose link exists but whose
     * reservation never ran (process died in between) reserves on replay
     * before the link is returned.
     */
    public function test_resume_reserves_a_pending_order_that_has_a_link_but_no_reservation(): void
    {
        $voucher = $this->voucher(500);
        $order = $this->service()->initiate($this->request(['voucherCode' => 'KRS-RACE']), $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-1']));
        VoucherRedemption::query()->delete();
        Voucher::query()->whereKey($voucher->id)->update(['remaining' => 500, 'status' => 'active']);

        $resumed = $this->service()->resume($order, $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-2']), 'DUITNOW_PAY');

        $this->assertSame(PaymentStatus::Pending, $resumed->payment_status);
        $this->assertSame('pr-1', $resumed->payment_ref, 'no second link is minted');
        $this->assertSame(0, $voucher->fresh()->remaining);
        $this->assertSame(1, VoucherRedemption::query()->count());
    }

    public function test_resume_fails_closed_when_the_late_reservation_loses(): void
    {
        $voucher = $this->voucher(500);
        $order = $this->service()->initiate($this->request(['voucherCode' => 'KRS-RACE']), $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-1']));
        VoucherRedemption::query()->delete();
        Voucher::query()->whereKey($voucher->id)->update(['remaining' => 0, 'status' => 'exhausted']);

        try {
            $this->service()->resume($order, $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-2']), 'DUITNOW_PAY');
            $this->fail('Expected CheckoutAttemptClosedException was not thrown.');
        } catch (CheckoutAttemptClosedException) {
            // expected
        }

        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

    /** A Paid replay (the customer already paid) is unchanged. */
    public function test_resume_returns_a_paid_order_untouched(): void
    {
        $order = $this->service()->initiate($this->request(), $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-1']));
        $order->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-2']);

        $resumed = $this->service()->resume($order, $gateway, 'DUITNOW_PAY');

        $this->assertSame(PaymentStatus::Paid, $resumed->payment_status);
        $this->assertSame(0, $gateway->calls);
    }
}
