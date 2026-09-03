<?php

namespace App\Services\Supplier;

/**
 * The canonical shape of one product listed by a supplier's catalog
 * (ADAPT-2) — what `SupplierAdapter::listProducts()`'s `data` array
 * contains. Formalized as a type (rather than a raw associative array)
 * so a second supplier adapter is compiler-enforced to normalize into
 * this same shape, not just conventionally expected to (ADAPT-3).
 *
 * `groupLabel` (ADR-067 decision 4) is the string the Product Manager
 * groups a supplier's raw catalog by — set by the adapter itself,
 * because only the adapter knows which of its supplier's fields
 * carries the "which game" identity: Gamevion's `category` is already
 * edition-level, so it passes `category`; Digiflazz's `category` is a
 * flat `"Games"` for every game, so it passes `brand`. `null` means
 * "no opinion" and `ProductSyncService` falls back to `category`.
 * `type` is the supplier's own sub-classification (e.g. Digiflazz
 * membership tiers) — stored raw for a future checkout need, never
 * used for grouping.
 */
final class SupplierCatalogItem
{
    public function __construct(
        public readonly string $productRef,
        public readonly ?string $name,
        public readonly ?string $category,
        public readonly ?float $price,
        public readonly ?string $status,
        public readonly ?string $groupLabel = null,
        public readonly ?string $type = null,
        // ADR-069 decision 10 — the supplier's own pre-conversion
        // price + currency, set by the adapter from its own catalog
        // shape. Display-only (a Product Manager sanity line); the
        // converted `price` above stays the one value everything
        // downstream uses.
        public readonly ?float $rawPrice = null,
        public readonly ?string $rawCurrency = null,
    ) {}
}
