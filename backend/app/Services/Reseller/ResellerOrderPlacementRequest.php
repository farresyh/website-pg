<?php

namespace App\Services\Reseller;

/**
 * Inputs ResellerOrderPlacementService needs to place a wallet-debited
 * order. Mirrors CheckoutRequest's own shape (game_id/package_id/
 * supplier_id/supplier_product_ref accepted as already-resolved values —
 * resolving them from the catalog is the caller's problem). Built once
 * here for both future callers (ADR-074 Reseller API, ADR-075 Reseller
 * Bot) to share — the same pattern CheckoutRequest already established
 * for API/storefront callers of CheckoutService.
 */
final class ResellerOrderPlacementRequest
{
    public function __construct(
        public readonly string $playerId,
        public readonly ?string $serverId,
        public readonly int $costPriceSen,
        public readonly int $standardSellingPriceSen,
        public readonly string $idempotencyKey,
        public readonly ?string $supplierProductRef = null,
        public readonly ?int $gameId = null,
        public readonly ?int $packageId = null,
        public readonly ?int $supplierId = null,
    ) {}
}
