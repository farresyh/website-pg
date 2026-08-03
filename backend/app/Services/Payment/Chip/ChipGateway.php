<?php

namespace App\Services\Payment\Chip;

use App\Services\Http\TransientFailureRetryPolicy;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for CHIP Collect's Purchases API (docs.chip-in.asia, spec
 * fetched live 2026-08-03 — confirmed against the real OpenAPI
 * document, not assumed, per this project's own twice-bitten "docs
 * alone aren't enough" history, ADR-022 decision 5). Scoped to
 * Malaysia-local channels only (FPX first), per ADR-022 decision 3.
 *
 * Confirmed facts this adapter relies on:
 *  - Auth is `Authorization: Bearer <secret key>`; no separate
 *    sandbox base URL exists — testing uses the same production
 *    endpoint with a test-mode key.
 *  - `POST /purchases/` creates a Purchase; the customer-facing
 *    redirect is a single `checkout_url` string (not an `actions`
 *    array like Xendit) — CHIP's own hosted page handles bank
 *    selection for FPX, no bank code is ever sent by this adapter.
 *  - `GET /purchases/{id}/` returns the same Purchase shape.
 *  - Purchase `status` real terminal values: `paid` (success),
 *    `error`/`cancelled` (failure); everything else (`created`,
 *    `hold`, `pending_*`, `refunded`, `released`, `preauthorized`) is
 *    still in flight.
 *  - Webhook authenticity: `X-Signature` header is a base64-encoded
 *    RSA PKCS#1 v1.5 signature of the SHA256 digest of the raw
 *    request body, verified against the public key from
 *    `GET /public_key/` (cached — see `webhookPublicKeyTtlSeconds`).
 *  - Webhook payload is the Purchase object itself with an added
 *    `event_type` field (flattened, not nested under a `purchase` key
 *    — only the amount stays nested at `purchase.total`, same as the
 *    Purchase resource shape).
 */
final class ChipGateway implements PaymentGateway
{
    private const PUBLIC_KEY_CACHE_KEY = 'payment-gateway.chip.public_key';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly string $brandId,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $webhookPublicKeyTtlSeconds = 86400,
    ) {
    }

    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $response = $this->client()->post('/purchases/', array_filter([
            'client' => $this->clientPayload($request->customer),
            'purchase' => array_filter([
                'products' => [[
                    'name' => $request->description ?? 'Order',
                    'price' => $request->amountSen,
                ]],
                'currency' => $request->currency,
            ], fn ($value) => $value !== null),
            'brand_id' => $this->brandId,
            'reference' => $request->referenceId,
            'success_redirect' => $request->channelProperties['success_return_url'] ?? null,
            'failure_redirect' => $request->channelProperties['failure_return_url'] ?? null,
            // CHIP's own channel enum ('fpx', 'fpx_b2b1', ...) is
            // stored directly as payment_methods.channel_code — no
            // translation table needed, same pass-through convention
            // XenditGateway already uses for its own channel_code.
            'payment_method_whitelist' => [$request->channelCode],
        ], fn ($value) => $value !== null));

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $normalized = $this->normalizePurchase($response->json());

        return PaymentResponse::success($normalized, status: $this->mapPurchaseStatus($normalized['status'] ?? ''));
    }

    public function getPayment(string $paymentRequestId): PaymentResponse
    {
        $response = $this->client()->get("/purchases/{$paymentRequestId}/");

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $normalized = $this->normalizePurchase($response->json());

        return PaymentResponse::success($normalized, status: $this->mapPurchaseStatus($normalized['status'] ?? ''));
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-Signature');

        if (! $signature) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            return false;
        }

        $publicKey = $this->publicKey();

        if ($publicKey === null) {
            return false;
        }

        return openssl_verify($request->getContent(), $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent
    {
        return new PaymentWebhookEvent(
            eventType: $payload['event_type'] ?? 'unknown',
            referenceId: $payload['reference'] ?? '',
            paymentRequestId: $payload['id'] ?? '',
            status: $this->mapPurchaseStatus($payload['status'] ?? ''),
            amountSen: (int) ($payload['purchase']['total'] ?? 0),
        );
    }

    /**
     * Fetched once and cached — CHIP's public key rotates rarely, and
     * this is on the webhook-delivery path, not worth a live HTTP call
     * per callback. A failed fetch is never cached, so the next
     * webhook delivery simply retries it rather than being locked out
     * for the full TTL.
     */
    private function publicKey(): ?string
    {
        $cached = Cache::get(self::PUBLIC_KEY_CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $response = $this->client()->get('/public_key/');

        if (! $response->successful()) {
            return null;
        }

        $key = $response->json();
        Cache::put(self::PUBLIC_KEY_CACHE_KEY, $key, $this->webhookPublicKeyTtlSeconds);

        return $key;
    }

    /**
     * CHIP requires `client.email` at minimum — a null customer or a
     * customer with no email produces an empty client object, which
     * CHIP will reject with a real validation error surfaced via
     * failureFrom(), same as XenditGateway never inventing a fallback
     * value for a field it wasn't given.
     */
    private function clientPayload(?PaymentCustomer $customer): array
    {
        if ($customer === null) {
            return [];
        }

        return array_filter([
            'email' => $customer->email,
            'full_name' => $customer->givenNames,
            'phone' => $customer->mobileNumber,
        ], fn ($value) => $value !== null);
    }

    private function mapPurchaseStatus(string $rawStatus): PaymentStatus
    {
        return match ($rawStatus) {
            'paid' => PaymentStatus::Paid,
            'error', 'cancelled' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->retry([200, 500, 1000], when: TransientFailureRetryPolicy::shouldRetry(), throw: false);
    }

    /**
     * CHIP's error-response body shape isn't confirmed against a real
     * account yet (no live key exists — ADR-022 decision 5) — reads
     * defensively across a couple of plausible shapes rather than
     * assuming one, same discipline XenditGateway's own failureFrom()
     * doc comment describes for the same reason.
     */
    private function failureFrom(Response $response): ?PaymentResponse
    {
        if ($response->failed()) {
            $body = $response->json() ?? [];

            $message = $body['detail'] ?? $body['message'] ?? "CHIP request failed with HTTP {$response->status()}";

            return PaymentResponse::failure((string) $response->status(), (string) $message);
        }

        return null;
    }

    private function normalizePurchase(array $body): array
    {
        return [
            'payment_request_id' => $body['id'] ?? null,
            'reference_id' => $body['reference'] ?? null,
            'status' => $body['status'] ?? null,
            // Normalized into the same {type, descriptor, value} shape
            // Xendit's real actions array uses (ADR-006's newest
            // addendum), so extractCheckoutRedirectUrl() on the
            // storefront needs zero CHIP-specific logic.
            'actions' => isset($body['checkout_url']) ? [[
                'type' => 'REDIRECT',
                'descriptor' => 'WEB_URL',
                'value' => $body['checkout_url'],
            ]] : [],
            'amount_sen' => $body['purchase']['total'] ?? null,
        ];
    }
}
