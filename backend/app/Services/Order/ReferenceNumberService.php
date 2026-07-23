<?php

namespace App\Services\Order;

use Illuminate\Support\Str;

/**
 * Generates the `reference_number` idempotency key (ORD-8): unique,
 * generated before the first supplier API call, and reused on every
 * retry of the same order — never regenerated. Distinct from the
 * customer-facing `order_number` and the supplier's own `supplier_ref`.
 * Per-supplier format transformation belongs to the Adapter layer
 * (ADAPT-4), not here — this class only owns the canonical internal
 * value.
 */
final class ReferenceNumberService
{
    private const PREFIX = 'REF-';

    public function generate(): string
    {
        return self::PREFIX.Str::ulid();
    }

    /**
     * Returns the existing reference number unchanged when one is
     * already set. Only generates a new one when none exists yet.
     */
    public function resolve(?string $existingReferenceNumber): string
    {
        if ($existingReferenceNumber !== null && $existingReferenceNumber !== '') {
            return $existingReferenceNumber;
        }

        return $this->generate();
    }
}
