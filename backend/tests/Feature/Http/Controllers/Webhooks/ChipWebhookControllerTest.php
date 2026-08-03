<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-022's newest addendum, decision 5 — mirrors
 * XenditWebhookControllerTest's own coverage (order lifecycle,
 * PAY-2 duplicate guard, queued fulfillment), but drives the route
 * through a genuinely RSA-signed request the same way ChipGatewayTest
 * proves ChipGateway::verifyWebhookSignature() itself, rather than
 * faking the gateway — this test is the one place that proves the
 * real ChipGateway is wired into the real route end-to-end.
 */
class ChipWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakePaidOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_gateway' => 'chip',
            'payment_ref' => 'chip-purchase-1',
        ], $overrides));
    }

    private function fakeSupplierAdapter(): void
    {
        $this->app->bind(SupplierAdapter::class, fn () => new class implements SupplierAdapter
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

            public function checkStatus(string $supplierRef): SupplierResponse
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
     * request thread, same discipline as XenditWebhookController.
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
}
