<?php

namespace App\Services\Payment;

use App\Services\Order\PaymentStatus;

/**
 * Canonical parsed webhook event. Reuses the existing PaymentStatus
 * enum (App\Services\Order) rather than inventing a parallel one — a
 * webhook event's whole purpose is to drive that same state machine.
 */
final class PaymentWebhookEvent
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $referenceId,
        public readonly string $paymentRequestId,
        public readonly PaymentStatus $status,
        public readonly int $amountSen,
        public readonly ?string $failureCode = null,
    ) {
    }
}
