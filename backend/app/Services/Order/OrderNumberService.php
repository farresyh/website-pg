<?php

namespace App\Services\Order;

use Illuminate\Support\Str;

/**
 * Generates the customer-facing order_number — distinct from
 * reference_number (our idempotency key sent to suppliers, ORD-8) and
 * supplier_ref (the supplier's own opaque order id). Assigned exactly
 * once at order creation; unlike reference_number, there is no
 * retry/reuse semantics for this value, so no resolve() method exists.
 */
final class OrderNumberService
{
    private const PREFIX = 'KRS-';

    public function generate(): string
    {
        return self::PREFIX.Str::ulid();
    }
}
