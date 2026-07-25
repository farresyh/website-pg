<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PricingService;

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
    ) {
    }

    /**
     * Deliberately NOT wrapped in one DB transaction spanning the
     * payment-gateway call: the Order is created and committed FIRST.
     * If the gateway call then fails, or the connection drops right
     * after it succeeds, the failure mode is the safe direction — an
     * Order stuck at Pending with no payment_ref, recoverable by
     * retry — rather than the dangerous direction a wrapping
     * transaction would risk: a real, payable Xendit payment link
     * existing with no matching Order anywhere in the system if the
     * transaction rolled back after Xendit had already accepted it.
     *
     * $gateway is caller-resolved (CheckoutController looks up the
     * matched PaymentMethod row's `gateway` column via
     * PaymentGatewayFactory) rather than constructor-injected — a
     * single fixed gateway can't serve a checkout that routes
     * different channels to different gateways (multi-gateway seam,
     * 2026-07-25, see the payment_methods migration's doc comment).
     */
    public function initiate(CheckoutRequest $request, PaymentGateway $gateway): Order
    {
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
            'customer_name' => $request->customerName,
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

        $payment = $gateway->createPayment(new PaymentRequest(
            referenceId: $order->order_number,
            amountSen: $total->finalAmount,
            currency: 'MYR',
            country: 'MY',
            channelCode: $request->channelCode,
            channelProperties: $request->channelProperties,
            description: "KedaiRuncitSoloz order {$order->order_number}",
            customer: new PaymentCustomer(
                referenceId: $order->order_number,
                givenNames: $request->customerName,
                email: $request->customerEmail,
                mobileNumber: $request->customerPhone,
            ),
        ));

        if (! $payment->success) {
            throw new CheckoutFailedException(
                "Payment request creation failed for order {$order->order_number}: [{$payment->errorCode}] {$payment->errorMessage}",
            );
        }

        $order->update([
            'payment_ref' => $payment->data['payment_request_id'] ?? null,
        ]);

        return $order->fresh();
    }
}
