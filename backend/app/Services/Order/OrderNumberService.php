<?php

namespace App\Services\Order;

use Illuminate\Support\Str;

/**
 * Generates the customer-facing order_number — distinct from
 * reference_number (our idempotency key sent to suppliers, ORD-8) and
 * supplier_ref (the supplier's own opaque order id). Assigned exactly
 * once at order creation; unlike reference_number, there is no
 * retry/reuse semantics for this value, so no resolve() method exists.
 *
 * Format: `PG-` + 12 uppercase base36 chars (ADR-062 addendum). Shorter
 * than the previous ULID form at the founder's request — it lands on the
 * customer's bank/e-wallet statement. Uniqueness is not structural the
 * way a ULID's is: 36^12 (~4.7e18) makes a collision negligible at this
 * platform's order volume, and `orders.order_number`'s UNIQUE constraint
 * is the hard backstop (a collision fails that one checkout, never
 * silently double-books).
 */
final class OrderNumberService
{
    private const PREFIX = 'PG-';

    public function generate(): string
    {
        return self::PREFIX.Str::upper(Str::random(12));
    }
}
