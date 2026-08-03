<?php

namespace App\Services\Payment;

use Illuminate\Http\Request;

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
     * Takes the whole incoming webhook Request, not a pre-extracted
     * header value — different gateways verify authenticity by
     * fundamentally different means (Xendit: a plain token compare
     * against one header; CHIP: an RSA signature over the raw request
     * body, ADR-022's newest addendum decision 3), and this shape
     * never needs to widen again for whatever a future gateway's own
     * scheme turns out to need. Each implementation reads only the
     * header(s)/body it actually requires, same self-contained-adapter
     * discipline as SupplierAdapter (ADR-006).
     */
    public function verifyWebhookSignature(Request $request): bool;

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent;
}
