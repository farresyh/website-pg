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
 * alone aren't enough" history, ADR-022 decision 5). The sole payment
 * gateway since ADR-022's 2026-09-01 addendum (Xendit removed); it
 * serves whatever CHIP Collect channels an admin activates —
 * `payment_method_whitelist` is a straight pass-through of the row's
 * `channel_code`, so a new method never needs an adapter change.
 * Launch scope is FPX + DuitNow QR (ADR-022 2026-09-01 addendum
 * decision 4); e-wallets/cards/BNPL follow per that decision.
 *
 * Confirmed facts this adapter relies on:
 *  - Auth is `Authorization: Bearer <secret key>`; no separate
 *    sandbox base URL exists — testing uses the same production
 *    endpoint with a test-mode key.
 *  - `POST /purchases/` creates a Purchase; the customer-facing
 *    redirect is a single `checkout_url` string — CHIP's own hosted
 *    page handles bank/wallet selection, no bank code is ever sent by
 *    this adapter.
 *  - `GET /purchases/{id}/` returns the same Purchase shape.
 *  - Purchase `status` real terminal values: `paid` (success),
 *    `error`/`cancelled` (failure); everything else (`created`,
 *    `hold`, `pending_*`, `refunded`, `released`, `preauthorized`) is
 *    still in flight.
 *  - Webhook delivery: this adapter uses CHIP's per-purchase
 *    `success_callback` (a URL sent on `POST /purchases/`), not a
 *    portal-registered webhook — ADR-022's 2026-09-04 webhook-model
 *    addendum. CHIP POSTs a signed Purchase to that URL when the
 *    purchase is paid; failure/cancel/chargeback outcomes are NOT
 *    delivered this way and are caught by `ReconcilePendingPaymentsCommand`
 *    (PAY-3 polling) instead.
 *  - Webhook authenticity: `X-Signature` header is a base64-encoded
 *    RSA PKCS#1 v1.5 signature of the SHA256 digest of the raw
 *    request body, verified against the public key from
 *    `GET /public_key/` (the account key — `success_callback` deliveries
 *    use it; a registered webhook would carry its own key instead).
 *    Cached for `webhookPublicKeyTtlSeconds`; a cached key that stops
 *    verifying (a CHIP-side rotation) is re-fetched once, rate-limited,
 *    and retried before the delivery is rejected.
 *  - Webhook payload is the Purchase object itself with an added
 *    `event_type` field (flattened, not nested under a `purchase` key
 *    — only the amount stays nested at `purchase.total`, same as the
 *    Purchase resource shape).
 */
final class ChipGateway implements PaymentGateway
{
    private const PUBLIC_KEY_CACHE_KEY = 'payment-gateway.chip.public_key';

    private const PUBLIC_KEY_REFRESH_COOLDOWN_KEY = 'payment-gateway.chip.public_key.refresh_cooldown';

    private const PUBLIC_KEY_REFRESH_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly string $brandId,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $webhookPublicKeyTtlSeconds = 86400,
        private readonly ?string $callbackUrl = null,
    ) {}

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
            // Server-to-server paid notification (ADR-022 2026-09-04
            // addendum). `success_redirect`/`failure_redirect` below are
            // only the customer's browser landing — never trusted to move
            // an order's payment_status.
            'success_callback' => $this->callbackUrl,
            'success_redirect' => $request->channelProperties['success_return_url'] ?? null,
            'failure_redirect' => $request->channelProperties['failure_return_url'] ?? null,
            // CHIP's own channel enum ('fpx', 'fpx_b2b1', 'duitnow_qr',
            // ...) is stored directly as payment_methods.channel_code and
            // passed straight through — no translation table. Supporting
            // a new CHIP method is a PaymentMethodSeeder row plus admin
            // activation, never an adapter change (ADR-022 2026-09-01
            // addendum decision 3).
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

        $body = $request->getContent();
        $cachedKey = $this->publicKey();

        if ($cachedKey !== null && $this->signatureMatches($body, $decodedSignature, $cachedKey)) {
            return true;
        }

        // A cached key that no longer verifies is what a CHIP-side key
        // rotation looks like from here. Re-fetch once (rate-limited, so a
        // flood of forged signatures can't hammer GET /public_key/) and
        // retry against a genuinely different key before rejecting. A
        // still-rejected delivery falls through to PAY-3 polling.
        $freshKey = $this->refreshPublicKey();

        return $freshKey !== null
            && $freshKey !== $cachedKey
            && $this->signatureMatches($body, $decodedSignature, $freshKey);
    }

    private function signatureMatches(string $body, string $decodedSignature, string $publicKey): bool
    {
        return openssl_verify($body, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
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

        return $this->fetchPublicKey();
    }

    /**
     * Drop the cached key and re-fetch — for the "cached key stopped
     * verifying" (rotation) path in verifyWebhookSignature(). Guarded by
     * a short cooldown so repeated bad-signature requests trigger at most
     * one live fetch per {@see self::PUBLIC_KEY_REFRESH_COOLDOWN_SECONDS}.
     */
    private function refreshPublicKey(): ?string
    {
        if (Cache::get(self::PUBLIC_KEY_REFRESH_COOLDOWN_KEY)) {
            return null;
        }

        Cache::put(self::PUBLIC_KEY_REFRESH_COOLDOWN_KEY, true, self::PUBLIC_KEY_REFRESH_COOLDOWN_SECONDS);
        Cache::forget(self::PUBLIC_KEY_CACHE_KEY);

        return $this->fetchPublicKey();
    }

    private function fetchPublicKey(): ?string
    {
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
     * failureFrom(). This adapter never invents a fallback value for a
     * field it wasn't given.
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
     * account yet (ADR-022 decision 5 — verify via app:chip-smoke-test
     * before trusting any live channel) — reads defensively across a
     * couple of plausible shapes rather than assuming one.
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
            // Normalized into the {type, descriptor, value} action shape
            // the storefront's extractCheckoutRedirectUrl() already
            // consumes, so it needs zero gateway-specific logic. CHIP's
            // hosted checkout page handles bank/wallet selection and (per
            // ADR-022 2026-09-01 addendum decision 5, to be confirmed via
            // smoke test) renders the DuitNow QR itself — so every CHIP
            // channel this platform activates is a single redirect.
            'actions' => isset($body['checkout_url']) ? [[
                'type' => 'REDIRECT',
                'descriptor' => 'WEB_URL',
                'value' => $body['checkout_url'],
            ]] : [],
            'amount_sen' => $body['purchase']['total'] ?? null,
        ];
    }
}
