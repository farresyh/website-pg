<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * ADR-060 PR-4a: the one place an `orders` row is written on a money
 * path. Before this, `Order::query()->create([...])` was hand-built in
 * CheckoutService and ResellerOrderPlacementService (and the sandbox
 * tool) with divergent column sets and each its own copy of the
 * unique-constraint catch — a fourth channel (ADR-060 affiliate
 * storefront) would have deepened that. Every caller now hands an
 * OrderDraft to `create()` and gets back a persisted Order or a
 * DuplicateOrderException.
 *
 * Deliberately narrow — it owns the row shape and the `23000` catch,
 * nothing else. The payment leg and the FulfillOrderJob dispatch stay in
 * the calling service: a storefront checkout (create → maybe gateway →
 * Pending) and a wallet order (create → debit → Paid → fulfil inside one
 * transaction) are genuinely different orchestrations and merging them
 * would recreate the coupling this split removes.
 *
 * Not to be confused with `Database\Factories\OrderFactory`, the Eloquent
 * test factory — this is the production write seam.
 */
final class OrderFactory
{
    public function __construct(private readonly OrderNumberService $orderNumbers) {}

    /**
     * @throws DuplicateOrderException when the INSERT hits a unique
     *                                 constraint (the `checkout_idempotency_key` index in practice).
     *                                 Any other database error — a genuine NOT NULL / FK violation,
     *                                 a connection failure — propagates unchanged as a QueryException,
     *                                 loudly, rather than being mistaken for a duplicate.
     */
    public function create(OrderDraft $draft): Order
    {
        try {
            return Order::query()->create([
                'order_number' => $this->orderNumbers->generate(),
                'checkout_idempotency_key' => $draft->idempotencyKey,
                'is_test' => $draft->isTest,
                'customer_email' => $draft->customerEmail,
                'customer_name' => $draft->customerName,
                'customer_phone' => $draft->customerPhone,
                'player_id' => $draft->playerId,
                'server_id' => $draft->serverId,
                'game_id' => $draft->gameId,
                'package_id' => $draft->packageId,
                'supplier_id' => $draft->supplierId,
                'supplier_product_ref' => $draft->supplierProductRef,
                'affiliate_id' => $draft->affiliateId,
                'wallet_reseller_id' => $draft->walletResellerId,
                'voucher_id' => $draft->voucherId,
                'pricing_basis' => $draft->pricing->basis->value,
                'membership_id' => $draft->pricing->membershipId,
                'member_discount_percent' => $draft->pricing->memberDiscountPercent,
                'normal_selling_price' => $draft->pricing->normalSellingPriceSen,
                'cost_price' => $draft->pricing->costPriceSen,
                'standard_selling_price' => $draft->pricing->standardSellingPriceSen,
                'affiliate_markup_pct' => $draft->affiliateMarkupPct,
                'wholesale_markup_pct' => $draft->pricing->wholesaleMarkupPct,
                'selling_price' => $draft->pricing->sellingPriceSen,
                'voucher_discount' => $draft->voucherDiscountSen,
                'transaction_fee' => $draft->transactionFeeSen,
                'final_amount' => $draft->resolvedFinalAmountSen(),
                'platform_profit' => $draft->pricing->platformProfitSen,
                'affiliate_profit' => $draft->pricing->affiliateProfitSen,
                'payment_status' => $draft->paymentStatus->value,
                'paid_at' => $draft->paidAt,
                'delivery_status' => $draft->deliveryStatus->value,
                'payment_method' => $draft->paymentMethod,
                'payment_gateway' => $draft->paymentGateway,
                'channel_code' => $draft->channelCode,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Laravel's own driver-aware detection (same subclass
            // VoucherService::redeem() already relies on) — narrower and
            // safer than the SQLSTATE `23000` string check the two
            // callers used inline before this seam, which also matched a
            // NOT NULL violation.
            throw new DuplicateOrderException(
                "Duplicate order for idempotency key {$draft->idempotencyKey}",
                previous: $e,
            );
        }
    }
}
