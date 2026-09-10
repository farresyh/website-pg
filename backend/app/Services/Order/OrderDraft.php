<?php

namespace App\Services\Order;

use App\Services\Pricing\PricingResolution;
use DateTimeInterface;

/**
 * Everything OrderFactory needs to write one `orders` row — a
 * PricingResolution (every price / profit / basis / membership value,
 * ADR-060 PR-1) plus the non-pricing fields each channel resolves for
 * itself (customer, player, catalog refs, payment state, channel).
 *
 * ADR-060 PR-4a: the single input type for the single order-creation
 * seam. CheckoutService and ResellerOrderPlacementService each build one
 * of these; nothing else constructs an `Order` on a money path.
 *
 * All amounts are integer sen.
 */
final readonly class OrderDraft
{
    /**
     * @param  string  $paymentMethod  the PaymentMethod category for a storefront
     *                                 order, the literal `'wallet'` for a reseller-wallet order.
     */
    public function __construct(
        public PricingResolution $pricing,
        public string $idempotencyKey,
        public string $customerEmail,
        public string $customerName,
        public ?string $customerPhone,
        public string $playerId,
        public ?string $serverId,
        public ?int $affiliateId,
        public PaymentStatus $paymentStatus,
        public ?DateTimeInterface $paidAt,
        public string $paymentMethod,
        public ?int $gameId = null,
        public ?int $packageId = null,
        public ?int $supplierId = null,
        public ?string $supplierProductRef = null,
        public ?int $walletResellerId = null,
        // ADR-084 PR-1 decision 5 — Reseller API channel only, else null.
        public ?string $resellerApiIdempotencyPayloadHash = null,
        public ?int $voucherId = null,
        public int $voucherDiscountSen = 0,
        public int $transactionFeeSen = 0,
        public ?int $finalAmountSen = null,
        // `orders.affiliate_markup_pct` is NOT NULL default 0 — a
        // reseller-wallet order layers no affiliate margin, so it stays 0.
        public float $affiliateMarkupPct = 0.0,
        public ?string $paymentGateway = null,
        public ?string $channelCode = null,
        public DeliveryStatus $deliveryStatus = DeliveryStatus::NotStarted,
        public bool $isTest = false,
    ) {}

    /**
     * The amount actually charged — explicit `finalAmountSen` when the
     * caller computed one (storefront: selling − voucher + fee), else the
     * selling price itself (reseller-wallet: no voucher, no fee).
     */
    public function resolvedFinalAmountSen(): int
    {
        return $this->finalAmountSen ?? $this->pricing->sellingPriceSen;
    }
}
