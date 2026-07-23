<?php

namespace App\Services\Supplier;

/**
 * The canonical outgoing order request business logic builds once.
 * Each Adapter transforms it into whatever field names/format a given
 * supplier's API expects (ADAPT-4) — business logic never constructs
 * a supplier-specific request shape itself.
 */
final class SupplierOrderRequest
{
    public function __construct(
        public readonly string $productRef,
        public readonly string $referenceNumber,
        public readonly string $playerId,
        public readonly ?string $serverId = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $callbackUrl = null,
    ) {
    }
}
