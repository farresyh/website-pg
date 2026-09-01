<?php

namespace App\Services\Payment\Fake;

use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Http\Request;

/**
 * ADR-023 decision #6 — the payment-layer counterpart to
 * FakeSupplierAdapter: a real, zero-network PaymentGateway bound only in
 * the `e2e` environment (AppServiceProvider::register()), so the
 * checkout golden-path spec exercises the whole checkout → webhook →
 * fulfillment pipeline against a real, separately-booted server without
 * needing any live payment credential.
 *
 * Before ADR-022's 2026-09-01 addendum the checkout spec hit Xendit's
 * real test sandbox and simulated the webhook with a shared token. CHIP
 * verifies webhooks with an RSA signature over the raw body — a payload
 * the spec cannot forge without CHIP's private key — so faking the
 * whole gateway for e2e (as the supplier layer already is) is both the
 * only workable shape and a strict improvement: the golden path now
 * needs no secret at all.
 *
 * Response shapes mirror ChipGateway's real normalisation so the spec
 * and the controllers exercise the same code paths.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $paymentRequestId = 'e2e-purchase-'.$request->referenceId;

        return PaymentResponse::success([
            'payment_request_id' => $paymentRequestId,
            'reference_id' => $request->referenceId,
            'status' => 'created',
            'actions' => [[
                'type' => 'REDIRECT',
                'descriptor' => 'WEB_URL',
                'value' => 'https://e2e.local/checkout/'.$paymentRequestId,
            ]],
            'amount_sen' => $request->amountSen,
        ], status: PaymentStatus::Pending);
    }

    public function getPayment(string $paymentRequestId): PaymentResponse
    {
        return PaymentResponse::success([
            'payment_request_id' => $paymentRequestId,
            'status' => 'paid',
        ], status: PaymentStatus::Paid);
    }

    /** e2e never delivers a signed callback — the spec POSTs the body directly. */
    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent
    {
        return new PaymentWebhookEvent(
            eventType: $payload['event_type'] ?? 'purchase.paid',
            referenceId: $payload['reference'] ?? '',
            paymentRequestId: $payload['id'] ?? '',
            status: match ($payload['status'] ?? '') {
                'paid' => PaymentStatus::Paid,
                'error', 'cancelled' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            amountSen: (int) ($payload['purchase']['total'] ?? 0),
        );
    }
}
