<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Jobs\FulfillOrderJob;
use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Membership\MembershipStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-022's 2026-08-03 addendum, decision 5 — covers the full order
 * lifecycle (payment status transition, PAY-2 duplicate guard, queued
 * fulfillment), and drives the route through a genuinely RSA-signed
 * request the same way ChipGatewayTest
 * proves ChipGateway::verifyWebhookSignature() itself, rather than
 * faking the gateway — this test is the one place that proves the
 * real ChipGateway is wired into the real route end-to-end.
 */
class ChipWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakePaidOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => 'gamevion'],
                ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
            )->id;

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
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
            'payment_ref' => 'chip-purchase-1',
        ], $overrides));
    }

    private function fakeSupplierAdapter(): void
    {
        $this->app->bind('supplier-adapter.gamevion', fn () => new class implements SupplierAdapter
        {
            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return SupplierResponse::success(['supplier_ref' => 'GV-CHIP-WEBHOOK-TEST']);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });
    }

    /**
     * Fakes CHIP's `GET /public_key/` (the real ChipGateway binding
     * fetches this to verify), returns the matching private key so
     * the caller can sign a real payload against it.
     */
    private function fakeChipPublicKey(): OpenSSLAsymmetricKey
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

        Http::fake(['gate.chip-in.asia/*' => Http::response(json_encode($publicKeyPem), 200)]);

        return openssl_pkey_get_private($privateKeyPem);
    }

    private function postSignedWebhook(array $payload, OpenSSLAsymmetricKey $privateKey): TestResponse
    {
        $rawBody = json_encode($payload);
        openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/api/webhooks/chip', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => base64_encode($signature),
        ], content: $rawBody);
    }

    public function test_rejects_a_webhook_with_an_invalid_signature(): void
    {
        $order = $this->fakePaidOrder();
        $this->fakeChipPublicKey();

        $response = $this->call('POST', '/api/webhooks/chip', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => base64_encode('not-a-real-signature'),
        ], content: json_encode(['event_type' => 'purchase.paid', 'id' => 'chip-purchase-1']));

        $response->assertUnauthorized();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_returns_404_when_no_order_matches_the_payment_request_id(): void
    {
        $privateKey = $this->fakeChipPublicKey();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-does-not-exist',
            'reference' => 'KRS-UNKNOWN',
            'status' => 'paid',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertNotFound();
    }

    /**
     * ADR-014: fulfillment must never run inline on the webhook
     * request thread (ADR-014).
     */
    public function test_dispatches_fulfillment_as_a_queued_job_instead_of_running_it_inline(): void
    {
        Queue::fake();
        $privateKey = $this->fakeChipPublicKey();
        $order = $this->fakePaidOrder();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'paid',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $order->fresh()->delivery_status);

        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    public function test_marks_paid_and_triggers_fulfillment_on_a_verified_paid_event(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $order = $this->fakePaidOrder();
        $this->fakeSupplierAdapter();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'paid',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $this->assertSame('GV-CHIP-WEBHOOK-TEST', $fresh->supplier_ref);
    }

    /**
     * PAY-2: a repeat webhook delivery for an already-paid order must
     * be acknowledged, not reprocessed.
     */
    public function test_acknowledges_without_reprocessing_when_already_paid(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $order = $this->fakePaidOrder([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'supplier_ref' => 'GV-ALREADY-DONE',
        ]);

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'paid',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame('GV-ALREADY-DONE', $order->fresh()->supplier_ref);
    }

    /**
     * Defense-in-depth: a paid webhook whose amount does not match the
     * order's final_amount is rejected, never fulfilled — the same
     * cross-check ReconcilePendingPaymentsCommand applies.
     */
    public function test_rejects_a_paid_webhook_when_the_amount_does_not_match_the_order(): void
    {
        Queue::fake();
        $privateKey = $this->fakeChipPublicKey();
        $order = $this->fakePaidOrder();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'paid',
            'purchase' => ['total' => 1],
        ], $privateKey);

        $response->assertStatus(409);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    public function test_marks_payment_failed_without_attempting_fulfillment_on_a_failure_event(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $order = $this->fakePaidOrder();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.payment_failure',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'error',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::Failed, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $fresh->delivery_status);
    }

    // --- ADR-068: the self-serve membership-subscription branch ---

    private function pendingAttempt(array $overrides = []): MembershipCheckoutAttempt
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return MembershipCheckoutAttempt::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'sub@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-WEBHOOKTEST',
            'idempotency_key' => 'idem-webhook-test',
            'payment_ref' => 'chip-purchase-ms-1',
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ], $overrides));
    }

    public function test_a_paid_membership_callback_activates_the_membership_and_marks_the_attempt_paid(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingAttempt();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-ms-1',
            'reference' => 'MS-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => $attempt->total_charged_sen],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $attempt->fresh()->status);

        $membership = Membership::query()
            ->where('affiliate_id', $this->primaryAffiliate()->id)
            ->where('email', 'sub@example.com')
            ->firstOrFail();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame(1, MembershipFeeRecord::query()->where('idempotency_key', 'MS-WEBHOOKTEST')->count());
        $this->assertSame($attempt->fee_sen, (int) LedgerEntry::query()->where('type', 'membership_fee')->sum('amount'));
    }

    public function test_a_repeat_membership_callback_is_acknowledged_without_double_booking(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingAttempt(['status' => MembershipCheckoutAttemptStatus::Paid->value]);

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-ms-1',
            'reference' => 'MS-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => $attempt->total_charged_sen],
        ], $privateKey);

        $response->assertOk()->assertJson(['message' => 'already processed']);
        $this->assertSame(0, MembershipFeeRecord::query()->count());
    }

    public function test_a_membership_callback_with_the_wrong_amount_is_rejected_409(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $this->pendingAttempt();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-ms-1',
            'reference' => 'MS-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => 1],
        ], $privateKey);

        $response->assertStatus(409);
        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, MembershipCheckoutAttempt::query()->firstOrFail()->status);
        $this->assertSame(0, Membership::query()->count());
    }

    public function test_an_order_callback_still_resolves_when_a_membership_attempt_row_exists(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $this->fakeSupplierAdapter();
        $order = $this->fakePaidOrder();
        // A membership attempt whose subscription_number is unrelated —
        // the order path must not be shadowed by it.
        $this->pendingAttempt(['subscription_number' => 'MS-UNRELATED', 'payment_ref' => 'chip-purchase-ms-x', 'idempotency_key' => 'idem-x']);

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-1',
            'reference' => $order->order_number,
            'status' => 'paid',
            'purchase' => ['total' => 1100],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, MembershipCheckoutAttempt::query()->firstOrFail()->status);
    }

    // --- ADR-073 decision 3(a) / PR-G: the self-serve wallet-top-up branch ---

    private function pendingWalletTopupAttempt(array $overrides = []): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return WalletTopupAttempt::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-WEBHOOKTEST',
            'amount_sen' => 5000,
            'total_charged_sen' => 5100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'chip_payment_ref' => 'chip-purchase-wt-1',
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    public function test_a_paid_wallet_topup_callback_credits_the_wallet_and_marks_the_attempt_paid(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingWalletTopupAttempt();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-wt-1',
            'reference' => 'WT-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => $attempt->total_charged_sen],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(5000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id));
    }

    public function test_a_repeat_wallet_topup_callback_is_acknowledged_without_double_crediting(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingWalletTopupAttempt();
        app(ResellerWalletService::class)->completeTopup($attempt);

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-wt-1',
            'reference' => 'WT-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => $attempt->total_charged_sen],
        ], $privateKey);

        $response->assertOk()->assertJson(['message' => 'already processed']);
        $this->assertSame(5000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id));
    }

    public function test_a_wallet_topup_callback_with_the_wrong_amount_is_rejected_409(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingWalletTopupAttempt();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.paid',
            'id' => 'chip-purchase-wt-1',
            'reference' => 'WT-WEBHOOKTEST',
            'status' => 'paid',
            'purchase' => ['total' => 1],
        ], $privateKey);

        $response->assertStatus(409);
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id));
    }

    public function test_a_failed_wallet_topup_callback_marks_the_attempt_failed(): void
    {
        $privateKey = $this->fakeChipPublicKey();
        $attempt = $this->pendingWalletTopupAttempt();

        $response = $this->postSignedWebhook([
            'event_type' => 'purchase.payment_failure',
            'id' => 'chip-purchase-wt-1',
            'reference' => 'WT-WEBHOOKTEST',
            'status' => 'error',
            'purchase' => ['total' => $attempt->total_charged_sen],
        ], $privateKey);

        $response->assertOk();
        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
    }
}
