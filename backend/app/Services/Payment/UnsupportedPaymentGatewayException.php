<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * Thrown when a `payment_methods.gateway` value has no corresponding
 * PaymentGateway implementation — deliberately fails loud rather than
 * silently falling back to Xendit, since a wrong gateway routing a
 * real customer payment is a financial-integrity bug, not a cosmetic
 * one.
 */
final class UnsupportedPaymentGatewayException extends RuntimeException
{
}
