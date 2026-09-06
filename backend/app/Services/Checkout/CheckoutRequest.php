<?php

namespace App\Services\Checkout;

use App\Services\Pricing\PaymentMethodFeeConfig;

/**
 * Inputs CheckoutService needs to create an Order + payment request.
 * game_id/package_id/supplier_id/affiliate_id/supplier_product_ref are
 * accepted as already-resolved values — the Game/Package/Affiliate
 * models don't exist yet, so resolving them from a catalog is the
 * caller's problem for now, not this service's.
 */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $customerEmail,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly string $playerId,
        public readonly ?string $serverId,
        public readonly int $costPriceSen,
        public readonly int $standardSellingPriceSen,
        public readonly float $packageMarkupPercent,
        public readonly float $affiliateMarkupPct,
        public readonly PaymentMethodFeeConfig $paymentFeeConfig,
        public readonly string $paymentMethod,
        public readonly string $paymentGateway,
        public readonly string $channelCode,
        public readonly string $idempotencyKey,
        public readonly array $channelProperties = [],
        public readonly ?string $voucherCode = null,
        public readonly ?string $supplierProductRef = null,
        public readonly ?int $gameId = null,
        public readonly ?int $packageId = null,
        public readonly ?int $supplierId = null,
        public readonly ?int $affiliateId = null,
        public readonly ?int $membershipId = null,
        // ADR-060 PR-4c: the `Host`-resolved storefront brand's active
        // wholesale-tier markup (`Affiliate::wholesaleTierMarkupPct()`).
        // Null (the default) means the primary brand / no tier / a lapsed
        // tier — priced exactly as the plain guest chain.
        public readonly ?float $tierMarkupPct = null,
    ) {}
}
