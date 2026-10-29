<?php

namespace App\Services\Membership;

use RuntimeException;

/**
 * ADR-110 2026-10-29 addendum — the attempt a retry (same idempotency key)
 * pointed at has failed for good: the customer cancelled at the bank and CHIP
 * ended the purchase. Its payment link is dead, so it is never handed out
 * again; the controller answers 422 `checkout_closed` and the storefront
 * starts a fresh attempt with a fresh key, exactly as for an order.
 */
class MembershipAttemptClosedException extends RuntimeException {}
