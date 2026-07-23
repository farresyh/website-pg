<?php

namespace App\Services\Payment;

/**
 * Common interface every payment gateway integration implements.
 * Thinner than SupplierAdapter on purpose (ADR-001 addendum): only one
 * gateway is ever in use at a time — this seam exists to isolate the
 * cost of swapping gateways (e.g. if Xendit rejects business
 * verification), not to run several concurrently.
 */
interface PaymentGateway
{
    public function createPayment(PaymentRequest $request): PaymentResponse;

    public function getPayment(string $paymentRequestId): PaymentResponse;

    /**
     * Compares the token from an incoming webhook's verification
     * header against the gateway's configured webhook secret.
     */
    public function verifyWebhookSignature(string $providedToken): bool;

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent;
}
