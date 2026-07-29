<?php

namespace App\Services\Checkout;

use App\Services\Pricing\PaymentMethodFeeConfig;

/**
 * Inputs CheckoutService needs to create an Order + payment request.
 * game_id/package_id/supplier_id/reseller_id/supplier_product_ref are
 * accepted as already-resolved values — the Game/Package/Reseller
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
        public readonly int $resellerCostPriceSen,
        public readonly float $resellerMarkupPct,
        public readonly PaymentMethodFeeConfig $paymentFeeConfig,
        public readonly string $paymentMethod,
        public readonly string $channelCode,
        public readonly string $idempotencyKey,
        public readonly array $channelProperties = [],
        public readonly int $voucherDiscountSen = 0,
        public readonly ?string $supplierProductRef = null,
        public readonly ?int $gameId = null,
        public readonly ?int $packageId = null,
        public readonly ?int $supplierId = null,
        public readonly ?int $resellerId = null,
    ) {
    }
}
