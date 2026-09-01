<?php

namespace App\Services\Payment;

use Illuminate\Http\Request;

/**
 * Common interface every payment gateway integration implements.
 * Thinner than SupplierAdapter on purpose (ADR-001 addendum): only one
 * gateway is ever in use at a time — this seam exists to isolate the
 * cost of swapping gateways, not to run several concurrently. CHIP is
 * the sole implementation today (ADR-022's 2026-09-01 addendum removed
 * Xendit); the seam is kept because a future multi-region ADR would
 * re-introduce a second gateway behind it.
 */
interface PaymentGateway
{
    public function createPayment(PaymentRequest $request): PaymentResponse;

    public function getPayment(string $paymentRequestId): PaymentResponse;

    /**
     * Takes the whole incoming webhook Request, not a pre-extracted
     * header value — a gateway verifies authenticity by whatever means
     * its own scheme dictates (CHIP: an RSA signature over the raw
     * request body, ADR-022's 2026-08-03 addendum decision 3), and this
     * shape never needs to widen for whatever a future gateway's own
     * scheme turns out to need. Each implementation reads only the
     * header(s)/body it actually requires, same self-contained-adapter
     * discipline as SupplierAdapter (ADR-006).
     */
    public function verifyWebhookSignature(Request $request): bool;

    public function parseWebhookEvent(array $payload): PaymentWebhookEvent;
}
