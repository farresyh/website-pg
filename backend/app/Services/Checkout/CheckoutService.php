<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates checkout INITIATION only — pricing through creating the
 * payment request. Everything after payment confirmation (supplier
 * delivery) is a separate concern: App\Services\Fulfillment\
 * OrderFulfillmentService. This split mirrors the domain itself —
 * payment_status and delivery_status are two independent state
 * machines (ORD-11) — not an arbitrary abstraction.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CheckoutTotalService $checkoutTotal,
        private readonly OrderNumberService $orderNumbers,
        private readonly PaymentGateway $paymentGateway,
    ) {
    }

    /**
     * Creates the Order row and the corresponding payment request
     * together, inside one transaction — either both succeed or
     * neither does, so a payment-gateway failure never leaves a
     * dangling Order with no way to pay it.
     */
    public function initiate(CheckoutRequest $request): Order
    {
        return DB::transaction(function () use ($request) {
            $pricing = $this->pricing->calculate(
                $request->costPriceSen,
                $request->resellerCostPriceSen,
                $request->resellerMarkupPct,
            );

            $total = $this->checkoutTotal->calculate(
                $pricing->sellingPrice,
                $request->voucherDiscountSen,
                $request->paymentFeeConfig,
            );

            $order = Order::query()->create([
                'order_number' => $this->orderNumbers->generate(),
                'customer_email' => $request->customerEmail,
                'customer_phone' => $request->customerPhone,
                'player_id' => $request->playerId,
                'server_id' => $request->serverId,
                'game_id' => $request->gameId,
                'package_id' => $request->packageId,
                'supplier_id' => $request->supplierId,
                'supplier_product_ref' => $request->supplierProductRef,
                'reseller_id' => $request->resellerId,
                'cost_price' => $pricing->costPrice,
                'reseller_cost_price' => $pricing->resellerCostPrice,
                'reseller_markup_pct' => $request->resellerMarkupPct,
                'selling_price' => $pricing->sellingPrice,
                'voucher_discount' => $total->voucherDiscount,
                'transaction_fee' => $total->transactionFee,
                'final_amount' => $total->finalAmount,
                'platform_profit' => $pricing->platformProfit,
                'reseller_profit' => $pricing->resellerProfit,
                'payment_status' => PaymentStatus::Pending->value,
                'delivery_status' => DeliveryStatus::NotStarted->value,
                'payment_method' => $request->paymentMethod,
            ]);

            $payment = $this->paymentGateway->createPayment(new PaymentRequest(
                referenceId: $order->order_number,
                amountSen: $total->finalAmount,
                currency: 'MYR',
                country: 'MY',
                channelCode: $request->channelCode,
                channelProperties: $request->channelProperties,
                description: "KedaiRuncitSoloz order {$order->order_number}",
            ));

            if (! $payment->success) {
                throw new CheckoutFailedException(
                    "Payment request creation failed: [{$payment->errorCode}] {$payment->errorMessage}",
                );
            }

            $order->update([
                'payment_ref' => $payment->data['payment_request_id'] ?? null,
            ]);

            return $order->fresh();
        });
    }
}
