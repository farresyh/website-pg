<?php

namespace App\Services\Supplier;

/**
 * The canonical shape of one product listed by a supplier's catalog
 * (ADAPT-2) — what `SupplierAdapter::listProducts()`'s `data` array
 * contains. Formalized as a type (rather than a raw associative array)
 * so a second supplier adapter is compiler-enforced to normalize into
 * this same shape, not just conventionally expected to (ADAPT-3).
 */
final class SupplierCatalogItem
{
    public function __construct(
        public readonly string $productRef,
        public readonly ?string $name,
        public readonly ?string $category,
        public readonly ?float $price,
        public readonly ?string $status,
    ) {
    }
}
