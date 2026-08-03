<?php

namespace Tests\Feature\Services\Payment;

use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\Xendit\XenditGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenditGatewayTest extends TestCase
{
    private function gateway(): XenditGateway
    {
        return new XenditGateway(
            baseUrl: 'https://api.xendit.co',
            secretKey: 'xnd_development_test_secret',
            webhookToken: 'test-webhook-token',
        );
    }

    public function test_create_payment_sends_basic_auth_and_api_version_header(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'ACCEPTING_PAYMENTS',
                'actions' => [],
                'request_amount' => 100.00,
            ], 201),
        ]);

        $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        Http::assertSent(function ($request) {
            return $request->hasHeader('api-version', '2024-11-11')
                && str_contains($request->header('Authorization')[0] ?? '', 'Basic ');
        });
    }

    /**
     * Our system stores money as integer sen everywhere (CheckoutTotal,
     * LedgerEntry). Xendit's request_amount is a decimal in the
     * currency's major unit — the gateway owns this conversion so
     * nothing above it ever touches a raw ringgit float.
     */
    public function test_create_payment_converts_sen_to_major_currency_unit(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'ACCEPTING_PAYMENTS',
                'actions' => [],
                'request_amount' => 105.50,
            ], 201),
        ]);

        $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10550,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        Http::assertSent(fn ($request) => $request['request_amount'] === 105.50);
    }

    public function test_create_payment_normalizes_a_successful_response(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'REQUIRES_ACTION',
                'actions' => [
                    ['type' => 'REDIRECT_CUSTOMER', 'descriptor' => 'WEB_URL', 'value' => 'https://checkout.xendit.co/web/pr-123'],
                ],
                'request_amount' => 100.00,
            ], 201),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('pr-123', $result->data['payment_request_id']);
        $this->assertSame('REQUIRES_ACTION', $result->data['status']);
        $this->assertSame('https://checkout.xendit.co/web/pr-123', $result->data['actions'][0]['value']);
    }

    /**
     * 2026-07-25: discovered live that Xendit requires exactly one of
     * `customer`/`customer_id` for at least FPX (see PaymentCustomer's
     * doc comment) — this proves the adapter actually sends the inline
     * `customer` object in the shape Xendit's docs specify, not just
     * that PaymentCustomer exists as a DTO.
     */
    public function test_create_payment_includes_the_customer_object_when_provided(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'ACCEPTING_PAYMENTS',
                'actions' => [],
                'request_amount' => 100.00,
            ], 201),
        ]);

        $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'AMBANK_FPX',
            customer: new PaymentCustomer(
                referenceId: 'KRS-1',
                givenNames: 'Buyer One',
                email: 'buyer@example.com',
                mobileNumber: '+60123456789',
            ),
        ));

        Http::assertSent(function ($request) {
            return $request['customer']['type'] === 'INDIVIDUAL'
                && $request['customer']['reference_id'] === 'KRS-1'
                && $request['customer']['email'] === 'buyer@example.com'
                && $request['customer']['mobile_number'] === '+60123456789'
                && $request['customer']['individual_detail']['given_names'] === 'Buyer One';
        });
    }

    public function test_create_payment_omits_customer_entirely_when_not_provided(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'ACCEPTING_PAYMENTS',
                'actions' => [],
                'request_amount' => 100.00,
            ], 201),
        ]);

        $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        Http::assertSent(fn ($request) => ! array_key_exists('customer', $request->data()));
    }

    public function test_create_payment_normalizes_a_validation_failure(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'error_code' => 'API_VALIDATION_ERROR',
                'message' => 'request_amount must be greater than 0',
            ], 400),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 0,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        $this->assertFalse($result->success);
        $this->assertSame('API_VALIDATION_ERROR', $result->errorCode);
        $this->assertSame('request_amount must be greater than 0', $result->errorMessage);
    }

    /**
     * Used by the reconciliation job (PAY-3) to independently confirm
     * status rather than relying solely on the webhook.
     */
    public function test_get_payment_normalizes_the_response(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'SUCCEEDED',
                'actions' => [],
                'request_amount' => 100.00,
            ], 200),
        ]);

        $result = $this->gateway()->getPayment('pr-123');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.xendit.co/v3/payment_requests/pr-123'
            && $request->method() === 'GET');

        $this->assertTrue($result->success);
        $this->assertSame('SUCCEEDED', $result->data['status']);
        $this->assertSame(10000, $result->data['amount_sen']);
        $this->assertSame(PaymentStatus::Paid, $result->status);
    }

    /**
     * ADR-022's newest addendum: a real gap found while building
     * ChipGateway — getPayment()/createPayment() must return a typed
     * PaymentStatus, never leave a business-logic caller
     * (ReconcilePendingPaymentsCommand) matching on Xendit's own raw
     * status vocabulary directly.
     */
    public function test_get_payment_maps_a_non_terminal_status_to_pending(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'REQUIRES_ACTION',
                'actions' => [],
                'request_amount' => 100.00,
            ], 200),
        ]);

        $result = $this->gateway()->getPayment('pr-123');

        $this->assertSame(PaymentStatus::Pending, $result->status);
    }

    public function test_get_payment_maps_expired_to_failed(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'EXPIRED',
                'actions' => [],
                'request_amount' => 100.00,
            ], 200),
        ]);

        $result = $this->gateway()->getPayment('pr-123');

        $this->assertSame(PaymentStatus::Failed, $result->status);
    }

    public function test_create_payment_maps_status_to_typed_pending(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'payment_request_id' => 'pr-123',
                'reference_id' => 'KRS-1',
                'status' => 'REQUIRES_ACTION',
                'actions' => [],
                'request_amount' => 100.00,
            ], 201),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        $this->assertSame(PaymentStatus::Pending, $result->status);
    }

    private function webhookRequest(string $token): Request
    {
        $request = Request::create('/api/webhooks/xendit', 'POST');
        $request->headers->set('x-callback-token', $token);

        return $request;
    }

    public function test_verify_webhook_signature_accepts_the_matching_token(): void
    {
        $this->assertTrue($this->gateway()->verifyWebhookSignature($this->webhookRequest('test-webhook-token')));
    }

    public function test_verify_webhook_signature_rejects_a_mismatched_token(): void
    {
        $this->assertFalse($this->gateway()->verifyWebhookSignature($this->webhookRequest('wrong-token')));
    }

    /**
     * Real example payload shape confirmed from Xendit's docs
     * (payment-webhook-notification).
     */
    public function test_parse_webhook_event_maps_payment_capture_succeeded_to_paid(): void
    {
        $event = $this->gateway()->parseWebhookEvent([
            'event' => 'payment.capture',
            'business_id' => '6094fa76c2fd53701b8e079c',
            'created' => '2021-12-02T14:52:21.566Z',
            'data' => [
                'payment_id' => 'py-1fdaf346',
                'status' => 'SUCCEEDED',
                'payment_request_id' => 'pr-1fdaf346',
                'request_amount' => '100.00',
                'reference_id' => 'KRS-1',
                'channel_code' => 'DUITNOW_PAY',
                'currency' => 'MYR',
            ],
        ]);

        $this->assertSame(PaymentStatus::Paid, $event->status);
        $this->assertSame('KRS-1', $event->referenceId);
        $this->assertSame('pr-1fdaf346', $event->paymentRequestId);
        $this->assertSame(10000, $event->amountSen);
    }

    public function test_parse_webhook_event_maps_payment_failure_to_failed(): void
    {
        $event = $this->gateway()->parseWebhookEvent([
            'event' => 'payment.failure',
            'data' => [
                'status' => 'FAILED',
                'payment_request_id' => 'pr-2',
                'reference_id' => 'KRS-2',
                'request_amount' => '50.00',
                'failure_code' => 'INSUFFICIENT_BALANCE',
            ],
        ]);

        $this->assertSame(PaymentStatus::Failed, $event->status);
        $this->assertSame('INSUFFICIENT_BALANCE', $event->failureCode);
    }

    public function test_parse_webhook_event_maps_payment_authorization_to_pending(): void
    {
        $event = $this->gateway()->parseWebhookEvent([
            'event' => 'payment.authorization',
            'data' => [
                'status' => 'AUTHORIZED',
                'payment_request_id' => 'pr-3',
                'reference_id' => 'KRS-3',
                'request_amount' => '75.00',
            ],
        ]);

        $this->assertSame(PaymentStatus::Pending, $event->status);
    }

    /**
     * ADR-014: same transient-failure retry policy as GamevionAdapter
     * (TransientFailureRetryPolicy) — a 5xx is retried automatically.
     */
    public function test_retries_a_transient_server_error_then_succeeds(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::sequence()
                ->push(['error_code' => 'SERVER_ERROR', 'message' => 'Internal error'], 500)
                ->push([
                    'payment_request_id' => 'pr-retried',
                    'reference_id' => 'KRS-RETRY-1',
                    'status' => 'ACCEPTING_PAYMENTS',
                    'actions' => [],
                    'request_amount' => 100.00,
                ], 201),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-RETRY-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        Http::assertSentCount(2);
        $this->assertTrue($result->success);
        $this->assertSame('pr-retried', $result->data['payment_request_id']);
    }

    /**
     * ADR-014: a 4xx (validation error — API_VALIDATION_ERROR is a
     * real Xendit error code confirmed live earlier this project) must
     * never be retried.
     */
    public function test_does_not_retry_a_validation_error(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'error_code' => 'API_VALIDATION_ERROR',
                'message' => 'request_amount must be a positive number',
            ], 400),
        ]);

        $result = $this->gateway()->createPayment(new PaymentRequest(
            referenceId: 'KRS-NO-RETRY-1',
            amountSen: 10000,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
        ));

        Http::assertSentCount(1);
        $this->assertFalse($result->success);
        $this->assertSame('API_VALIDATION_ERROR', $result->errorCode);
    }
}
