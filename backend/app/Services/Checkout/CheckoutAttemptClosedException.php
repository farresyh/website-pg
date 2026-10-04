<?php

namespace App\Services\Checkout;

/**
 * This checkout attempt can never be paid: its voucher or member-quota
 * reservation lost a race, or a replay hit an order that already
 * failed (ADR-024 2026-10-04 addendum). The storefront answers it with
 * a fresh idempotency key and a re-priced preview, never a retry of
 * the same attempt.
 */
final class CheckoutAttemptClosedException extends CheckoutFailedException
{
    public static function reservationLost(): self
    {
        return new self('Your voucher balance or member quota was just used by another order, so this order was not placed. Please review your order and try again.');
    }

    public static function alreadyFailed(): self
    {
        return new self('This checkout attempt has already closed. Please review your order and try again.');
    }
}
