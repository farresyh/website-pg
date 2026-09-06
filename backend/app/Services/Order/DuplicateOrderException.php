<?php

namespace App\Services\Order;

use RuntimeException;

/**
 * Thrown by OrderFactory when the `Order` INSERT hits a unique
 * constraint — in practice the `checkout_idempotency_key` index, from a
 * genuine race between two near-simultaneous requests carrying the same
 * key (each channel's own upfront lookup already misses that window).
 *
 * Channel-neutral on purpose: every order-creation path shares this one
 * seam (ADR-060 PR-4a), and each caller translates it into its own
 * channel vocabulary — CheckoutService re-throws it as a
 * DuplicateCheckoutAttemptException, ResellerOrderPlacementService
 * catches it and re-reads the winning Order for a no-op replay.
 */
final class DuplicateOrderException extends RuntimeException {}
