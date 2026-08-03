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
use Illuminate\Database\QueryException;

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

        // ADR-019 idempotency finding, verified directly against
        // docs.xendit.co (not assumed): Payment Request v3 has no
        // client-supplied idempotency-key header. Its real dedupe
        // mechanism is server-side reference_id uniqueness — a second
        // POST with the same reference_id (order_number, stable per
        // Order) gets a clean 409 DATA_NOT_FOUND "Duplication is not
        // allowed", never a second live payment request. So a
        // TransientFailureRetryPolicy retry *within* this one call is
        // already safe against double-charging. The gap that didn't
        // close on its own — a retried POST /api/checkout HTTP request
        // (customer double-click, client-side timeout retry) calling
        // initiate() again from scratch with a brand-new order_number
        // each time — is closed by $request->idempotencyKey below:
        // stamped onto the Order at creation (not after payment
        // succeeds), under a DB-level unique constraint, so a
        // genuinely concurrent duplicate request fails fast at the
        // INSERT rather than ever reaching the gateway a second time.
        try {
            $order = Order::query()->create([
                'order_number' => $this->orderNumbers->generate(),
                'checkout_idempotency_key' => $request->idempotencyKey,
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
                'payment_gateway' => $request->paymentGateway,
                'channel_code' => $request->channelCode,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new DuplicateCheckoutAttemptException(
                    "Duplicate checkout attempt for idempotency key {$request->idempotencyKey}",
                    previous: $e,
                );
            }

            throw $e;
        }

        return $this->requestPayment(
            $order,
            $gateway,
            channelCode: $request->channelCode,
            channelProperties: $request->channelProperties,
        );
    }

    /**
     * Retries the payment leg for an Order that already exists (found by
     * CheckoutController via checkout_idempotency_key) but never got a
     * payment_ref — the previous attempt's gateway call failed or the
     * process died before recording it. Reuses the Order's own already-
     * snapshotted pricing (ORD-9 — never recomputed here) and its own
     * order_number as the Xendit reference_id, same as a fresh
     * initiate() would, so Xendit's own reference_id dedupe still
     * applies if that earlier attempt actually reached Xendit despite
     * failing to persist locally.
     */
    public function resume(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties = []): Order
    {
        return $this->requestPayment($order, $gateway, $channelCode, $channelProperties);
    }

    private function requestPayment(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties): Order
    {
        // The storefront can't put order_number in the return URLs it
        // sends with the checkout request — it doesn't have one yet at
        // that point (this Order didn't exist until just above/earlier
        // in initiate()). Now that it does, overwrite whatever generic
        // URL the storefront sent with the real per-order tracking page,
        // so a redirect-based channel (FPX, some e-wallets) lands the
        // customer straight on their own order's status instead of the
        // general "look up an order" search page.
        $orderStatusUrl = rtrim((string) config('services.storefront.url'), '/')
            . '/order/status/' . $order->order_number;

        if (array_key_exists('success_return_url', $channelProperties)) {
            $channelProperties['success_return_url'] = $orderStatusUrl;
        }
        if (array_key_exists('failure_return_url', $channelProperties)) {
            $channelProperties['failure_return_url'] = $orderStatusUrl;
        }

        $payment = $gateway->createPayment(new PaymentRequest(
            referenceId: $order->order_number,
            amountSen: $order->final_amount,
            currency: 'MYR',
            country: 'MY',
            channelCode: $channelCode,
            channelProperties: $channelProperties,
            description: "KedaiRuncitSoloz order {$order->order_number}",
            customer: new PaymentCustomer(
                referenceId: $order->order_number,
                givenNames: $order->customer_name,
                email: $order->customer_email,
                mobileNumber: $order->customer_phone,
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

    /**
     * MySQL/sqlite both surface a unique-constraint violation as
     * SQLSTATE 23000 — narrow enough to not accidentally swallow an
     * unrelated QueryException (e.g. a real connection failure).
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
