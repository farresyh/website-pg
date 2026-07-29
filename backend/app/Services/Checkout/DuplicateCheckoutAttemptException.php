<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * Thrown when Order::create() hits the checkout_idempotency_key unique
 * constraint — a genuine race between two near-simultaneous requests
 * carrying the same key (CheckoutController's own upfront lookup
 * already misses this window). The controller catches this and re-reads
 * the winning Order by the same key rather than treating it as a real
 * checkout failure.
 */
final class DuplicateCheckoutAttemptException extends RuntimeException
{
}
