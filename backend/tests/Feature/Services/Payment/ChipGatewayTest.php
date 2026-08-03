<?php

namespace Tests\Feature\Services\Payment;

use App\Services\Order\PaymentStatus;
use App\Services\Payment\Chip\ChipGateway;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-022 decision 5 — built directly from CHIP Collect's published
 * OpenAPI spec (docs.chip-in.asia/openapi/chip-collect.yaml, fetched
 * live 2026-08-03, not assumed): Bearer auth, `POST /purchases/` to
 * create, `GET /purchases/{id}/` to retrieve, `GET /public_key/` for
 * webhook RSA verification. Http::fake()-based only — no live CHIP
 * account exists yet (ADR-022 decision 5's own explicit gate: not
 * "verified" until a real app:chip-smoke-test passes).
 */
class ChipGatewayTest extends TestCase
{
    private function gateway(int $publicKeyTtl = 86400): ChipGateway
    {
        return new ChipGateway(
            baseUrl: 'https://gate.chip-in.asia/api/v1',
            secretKey: 'sk_test_123',
            brandId: 'brand-uuid-123',
            webhookPublicKeyTtlSeconds: $publicKeyTtl,
        );
    }

    public function test_create_payment_sends_bearer_auth_and_the_expected_request_shape(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response([
                'id' => 'purchase-123',
                'status' => 'created',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-123/',
                'reference' => 'KRS-1',
                'purchase' => ['total' => 10000],
            ], 201),
        ]);

        $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
            channelProperties: [
                'success_return_url' => 'https://storefront.test/order/status/KRS-1',
                'failure_return_url' => 'https://storefront.test/order/status/KRS-1',
            ],
            description: 'KedaiRuncitSoloz order KRS-1',
            customer: new PaymentCustomer(
                referenceId: 'KRS-1',
                givenNames: 'Buyer One',
                email: 'buyer@example.com',
                mobileNumber: '+60123456789',
            ),
        ));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && $request->url() === 'https://gate.chip-in.asia/api/v1/purchases/'
                && $request['brand_id'] === 'brand-uuid-123'
                && $request['reference'] === 'KRS-1'
                && $request['client']['email'] === 'buyer@example.com'
                && $request['client']['full_name'] === 'Buyer One'
                && $request['client']['phone'] === '+60123456789'
                && $request['purchase']['currency'] === 'MYR'
                && $request['purchase']['products'][0]['price'] === 10000
                && $request['success_redirect'] === 'https://storefront.test/order/status/KRS-1'
                && $request['failure_redirect'] === 'https://storefront.test/order/status/KRS-1'
                && $request['payment_method_whitelist'] === ['fpx'];
            });
    }

    public function test_create_payment_normalizes_a_successful_response(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response([
                'id' => 'purchase-123',
                'status' => 'created',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-123/',
                'reference' => 'KRS-1',
                'purchase' => ['total' => 10000],
            ], 201),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
            customer: new PaymentCustomer(referenceId: 'KRS-1', givenNames: 'Buyer One', email: 'buyer@example.com'),
        ));

        $this->assertTrue($result->success);
        $this->assertSame('purchase-123', $result->data['payment_request_id']);
        $this->assertSame('KRS-1', $result->data['reference_id']);
        $this->assertSame(10000, $result->data['amount_sen']);
        $this->assertSame(PaymentStatus::Pending, $result->status);
    }

    /**
     * The storefront's extractCheckoutRedirectUrl() only understands
     * two shapes today: Xendit's real `actions: [{type, descriptor:
     * "WEB_URL", value}]` array, or a flat object with a
     * `*_checkout_url` key. CHIP's own response has neither — it's a
     * bare `checkout_url` string — so ChipGateway must normalize into
     * the same array shape Xendit already produces, or the storefront
     * would need CHIP-specific frontend logic (which it must never
     * need, per the gateway-agnostic PaymentResponse contract).
     */
    public function test_create_payment_normalizes_checkout_url_into_the_same_actions_shape_xendit_uses(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response([
                'id' => 'purchase-123',
                'status' => 'created',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-123/',
                'reference' => 'KRS-1',
                'purchase' => ['total' => 10000],
            ], 201),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
            customer: new PaymentCustomer(referenceId: 'KRS-1', givenNames: 'Buyer One', email: 'buyer@example.com'),
        ));

        $this->assertSame('WEB_URL', $result->data['actions'][0]['descriptor']);
        $this->assertSame('https://gate.chip-in.asia/p/purchase-123/', $result->data['actions'][0]['value']);
    }

    public function test_create_payment_normalizes_a_validation_failure(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response(['detail' => 'brand_id is invalid'], 400),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
        ));

        $this->assertFalse($result->success);
        $this->assertSame('brand_id is invalid', $result->errorMessage);
    }

    public function test_get_payment_fetches_by_id_and_normalizes_the_response(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response([
                'id' => 'purchase-123',
                'status' => 'paid',
                'reference' => 'KRS-1',
                'purchase' => ['total' => 10000],
            ], 200),
        ]);

        $result = $this->gateway()->getPayment('purchase-123');

        Http::assertSent(fn ($request) => $request->url() === 'https://gate.chip-in.asia/api/v1/purchases/purchase-123/'
            && $request->method() === 'GET');
        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::Paid, $result->status);
    }

    public function test_get_payment_maps_error_status_to_failed(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response(['id' => 'purchase-123', 'status' => 'error', 'purchase' => ['total' => 10000]], 200),
        ]);

        $result = $this->gateway()->getPayment('purchase-123');

        $this->assertSame(PaymentStatus::Failed, $result->status);
    }

    public function test_get_payment_maps_cancelled_status_to_failed(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response(['id' => 'purchase-123', 'status' => 'cancelled', 'purchase' => ['total' => 10000]], 200),
        ]);

        $result = $this->gateway()->getPayment('purchase-123');

        $this->assertSame(PaymentStatus::Failed, $result->status);
    }

    public function test_get_payment_maps_a_non_terminal_status_to_pending(): void
    {
        Http::fake([
            'gate.chip-in.asia/*' => Http::response(['id' => 'purchase-123', 'status' => 'hold', 'purchase' => ['total' => 10000]], 200),
        ]);

        $result = $this->gateway()->getPayment('purchase-123');

        $this->assertSame(PaymentStatus::Pending, $result->status);
    }

    public function test_parse_webhook_event_maps_a_paid_purchase(): void
    {
        $event = $this->gateway()->parseWebhookEvent([
            'event_type' => 'purchase.paid',
            'id' => 'purchase-123',
            'reference' => 'KRS-1',
            'status' => 'paid',
            'purchase' => ['total' => 10000],
        ]);

        $this->assertSame('purchase.paid', $event->eventType);
        $this->assertSame('KRS-1', $event->referenceId);
        $this->assertSame('purchase-123', $event->paymentRequestId);
        $this->assertSame(PaymentStatus::Paid, $event->status);
        $this->assertSame(10000, $event->amountSen);
    }

    public function test_parse_webhook_event_maps_a_payment_failure(): void
    {
        $event = $this->gateway()->parseWebhookEvent([
            'event_type' => 'purchase.payment_failure',
            'id' => 'purchase-123',
            'reference' => 'KRS-1',
            'status' => 'error',
            'purchase' => ['total' => 10000],
        ]);

        $this->assertSame(PaymentStatus::Failed, $event->status);
    }

    /**
     * Real RSA keypair generated in-test (not a live CHIP key) — signs
     * a raw body exactly the way CHIP's spec documents ("RSA PKCS#1
     * v1.5 signature of the SHA256 digest of the request body
     * buffer"), base64-encodes it into X-Signature, and proves
     * ChipGateway independently verifies it via the fetched public key
     * (GET /public_key/, faked here) rather than trusting anything the
     * request itself claims.
     */
    private function signedWebhookRequest(string $rawBody, \OpenSSLAsymmetricKey $privateKey): Request
    {
        openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $request = Request::create('/api/webhooks/chip', 'POST', content: $rawBody);
        $request->headers->set('X-Signature', base64_encode($signature));

        return $request;
    }

    public function test_verify_webhook_signature_accepts_a_validly_signed_body(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

        Http::fake(['gate.chip-in.asia/*' => Http::response(json_encode($publicKeyPem), 200)]);

        $rawBody = '{"event_type":"purchase.paid","id":"purchase-123"}';
        $request = $this->signedWebhookRequest($rawBody, openssl_pkey_get_private($privateKeyPem));

        $this->assertTrue($this->gateway()->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_rejects_a_body_that_was_tampered_with_after_signing(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

        Http::fake(['gate.chip-in.asia/*' => Http::response(json_encode($publicKeyPem), 200)]);

        $signedRequest = $this->signedWebhookRequest('{"event_type":"purchase.paid","id":"purchase-123"}', openssl_pkey_get_private($privateKeyPem));
        $tamperedRequest = Request::create('/api/webhooks/chip', 'POST', content: '{"event_type":"purchase.paid","id":"purchase-999"}');
        $tamperedRequest->headers->set('X-Signature', $signedRequest->headers->get('X-Signature'));

        $this->assertFalse($this->gateway()->verifyWebhookSignature($tamperedRequest));
    }

    public function test_verify_webhook_signature_rejects_a_signature_from_the_wrong_private_key(): void
    {
        $realKeyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $realPublicKeyPem = openssl_pkey_get_details($realKeyPair)['key'];

        $attackerKeyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($attackerKeyPair, $attackerPrivateKeyPem);

        Http::fake(['gate.chip-in.asia/*' => Http::response(json_encode($realPublicKeyPem), 200)]);

        $rawBody = '{"event_type":"purchase.paid","id":"purchase-123"}';
        $request = $this->signedWebhookRequest($rawBody, openssl_pkey_get_private($attackerPrivateKeyPem));

        $this->assertFalse($this->gateway()->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_rejects_a_missing_signature_header(): void
    {
        $request = Request::create('/api/webhooks/chip', 'POST', content: '{"event_type":"purchase.paid"}');

        $this->assertFalse($this->gateway()->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_caches_the_public_key_across_calls(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

        Http::fake(['gate.chip-in.asia/*' => Http::response(json_encode($publicKeyPem), 200)]);

        $gateway = $this->gateway();
        $gateway->verifyWebhookSignature($this->signedWebhookRequest('body-one', openssl_pkey_get_private($privateKeyPem)));
        $gateway->verifyWebhookSignature($this->signedWebhookRequest('body-two', openssl_pkey_get_private($privateKeyPem)));

        Http::assertSentCount(1);
    }
}
