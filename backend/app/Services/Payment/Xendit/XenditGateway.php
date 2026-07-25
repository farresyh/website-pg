<?php

namespace App\Services\Payment\Xendit;

use App\Services\Http\TransientFailureRetryPolicy;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for Xendit's Payment Request API v3 (docs.xendit.co,
 * api-version 2024-11-11 — the current API, superseding the older
 * Invoice API). Confirmed against the real docs, not assumed:
 *  - Auth is HTTP Basic Auth: secret key as username, empty password.
 *  - Webhook authenticity is a plain token comparison against the
 *    `x-callback-token` header value — not an HMAC signature.
 *  - request_amount is documented as a decimal number in the
 *    currency's major unit; this adapter converts to/from our
 *    internal integer-sen convention (unconfirmed against a live MYR
 *    sandbox response — verify empirically before production use).
 *  - `customer` (2026-07-25 addendum): at least FPX requires exactly
 *    one of `customer`/`customer_id` — discovered live via the
 *    Payment Methods "Test" action, see PaymentCustomer's own doc
 *    comment for the full story. This adapter always sends the inline
 *    `customer` object (type=INDIVIDUAL) when the caller provides one;
 *    it never invents `customer_id` (a reference to a pre-registered
 *    Xendit Customer resource this platform has no reason to create).
 */
final class XenditGateway implements PaymentGateway
{
    private const API_VERSION = '2024-11-11';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly string $webhookToken,
    ) {
    }

    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $response = $this->client()->post('/v3/payment_requests', array_filter([
            'reference_id' => $request->referenceId,
            'type' => 'PAY',
            'country' => $request->country,
            'currency' => $request->currency,
            'request_amount' => $this->senToMajorUnit($request->amountSen),
            'channel_code' => $request->channelCode,
            'channel_properties' => $request->channelProperties ?: null,
            'description' => $request->description,
            'metadata' => $request->metadata ?: null,
            'customer' => $request->customer ? $this->customerPayload($request->customer) : null,
        ], fn ($value) => $value !== null));

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        return PaymentResponse::success($this->normalizePaymentRequest($response->json()));
    }

    public function getPayment(string $paymentRequestId): PaymentResponse
    {
        $response = $this->client()->get("/v3/payment_requests/{$paymentRequestId}");

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        return PaymentResponse::success($this->normalizePaymentRequest($response->json()));
    }

    public function verifyWebhookSignature(string $providedToken): bool
    {
        return hash_equals($this->webhookToken, $providedToken);
    }

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent
    {
        $data = $payload['data'] ?? [];
        $eventType = $payload['event'] ?? 'unknown';

        return new PaymentWebhookEvent(
            eventType: $eventType,
            referenceId: $data['reference_id'] ?? '',
            paymentRequestId: $data['payment_request_id'] ?? '',
            status: $this->mapStatus($eventType, $data['status'] ?? ''),
            amountSen: $this->majorUnitToSen($data['request_amount'] ?? 0),
            failureCode: $data['failure_code'] ?? null,
        );
    }

    private function mapStatus(string $eventType, string $rawStatus): PaymentStatus
    {
        return match (true) {
            $eventType === 'payment.capture' && $rawStatus === 'SUCCEEDED' => PaymentStatus::Paid,
            $eventType === 'payment.failure' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->secretKey, '')
            ->withHeaders(['api-version' => self::API_VERSION])
            ->acceptJson()
            // ADR-014: same transient-failure-only retry policy as
            // GamevionAdapter — see TransientFailureRetryPolicy.
            ->retry([200, 500, 1000], when: TransientFailureRetryPolicy::shouldRetry(), throw: false);
    }

    /**
     * Xendit's error-response body isn't given in full by the docs
     * beyond named error codes (API_VALIDATION_ERROR, etc.) — reads
     * defensively rather than assuming an exact body shape.
     */
    private function failureFrom(Response $response): ?PaymentResponse
    {
        if ($response->failed()) {
            $body = $response->json() ?? [];

            return PaymentResponse::failure(
                $body['error_code'] ?? (string) $response->status(),
                $body['message'] ?? "Xendit request failed with HTTP {$response->status()}",
            );
        }

        return null;
    }

    private function customerPayload(PaymentCustomer $customer): array
    {
        return array_filter([
            'type' => 'INDIVIDUAL',
            'reference_id' => $customer->referenceId,
            'email' => $customer->email,
            'mobile_number' => $customer->mobileNumber,
            'individual_detail' => ['given_names' => $customer->givenNames],
        ], fn ($value) => $value !== null);
    }

    private function normalizePaymentRequest(array $body): array
    {
        return [
            'payment_request_id' => $body['payment_request_id'] ?? null,
            'reference_id' => $body['reference_id'] ?? null,
            'status' => $body['status'] ?? null,
            'actions' => $body['actions'] ?? [],
            'amount_sen' => isset($body['request_amount']) ? $this->majorUnitToSen($body['request_amount']) : null,
        ];
    }

    private function senToMajorUnit(int $sen): float
    {
        return round($sen / 100, 2);
    }

    private function majorUnitToSen(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
