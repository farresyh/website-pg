<?php

namespace App\Services\Checkout;

use App\Jobs\FulfillOrderJob;
use App\Models\Membership;
use App\Models\Order;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingService;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherPreview;
use App\Services\Voucher\VoucherService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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
        private readonly VoucherService $vouchers,
        private readonly MembershipPricingService $membershipPricing,
        private readonly MembershipQuotaService $membershipQuota,
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
            $request->standardSellingPriceSen,
            $request->resellerMarkupPct,
        );

        $member = $this->resolveMemberPricing($request);

        $sellingPriceForOrder = $member !== null ? $member->memberPriceSen : $pricing->sellingPrice;
        $platformProfit = $member !== null ? $member->memberPriceSen - $request->costPriceSen : $pricing->platformProfit;
        $resellerProfit = $member !== null ? 0 : $pricing->resellerProfit;

        // ADR-024 decision #3: resolved server-side from the voucher's
        // own stored code/remaining/ownership — never a client-
        // submitted discount amount (ORD-9). preview() only reads, it
        // never locks or mutates — the real, locked spend happens
        // later, in requestPayment()/settleWithVoucher() below,
        // matching decision #1's exact timing.
        $voucherPreview = $request->voucherCode !== null
            ? $this->vouchers->preview($request->voucherCode, $request->customerEmail, $request->customerPhone, $sellingPriceForOrder)
            : null;

        $total = $this->checkoutTotal->calculate(
            $sellingPriceForOrder,
            $voucherPreview?->discountSen ?? 0,
            $request->paymentFeeConfig,
        );

        // ADR-024 decision #5: a voucher covering the full price means
        // feeBase is 0 — CheckoutTotalService's own transactionFee
        // formula would still apply a payment method's flat-fee
        // component even at feeBase=0 (only the percentage component
        // scales with the base), which makes no sense for an order
        // that never touches a payment gateway at all. Forced to 0
        // here rather than trusting that formula for this branch.
        $fullyCoveredByVoucher = $total->feeBase === 0 && $voucherPreview !== null;

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
                'voucher_id' => $voucherPreview?->voucherId,
                'pricing_basis' => $member !== null ? PricingBasis::Member->value : PricingBasis::Standard->value,
                'membership_id' => $member?->membershipId,
                'member_discount_percent' => $member?->discountPercent,
                'normal_selling_price' => $member !== null ? $pricing->sellingPrice : null,
                'cost_price' => $pricing->costPrice,
                'standard_selling_price' => $pricing->standardSellingPrice,
                'reseller_markup_pct' => $request->resellerMarkupPct,
                'selling_price' => $sellingPriceForOrder,
                'voucher_discount' => $total->voucherDiscount,
                'transaction_fee' => $fullyCoveredByVoucher ? 0 : $total->transactionFee,
                'final_amount' => $fullyCoveredByVoucher ? 0 : $total->finalAmount,
                'platform_profit' => $platformProfit,
                'reseller_profit' => $resellerProfit,
                'payment_status' => $fullyCoveredByVoucher ? PaymentStatus::Paid->value : PaymentStatus::Pending->value,
                'paid_at' => $fullyCoveredByVoucher ? now() : null,
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

        if ($fullyCoveredByVoucher) {
            return $this->settleWithVoucher($order, $voucherPreview);
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

    /**
     * ADR-024 decision #5 — a voucher fully covers the price: no
     * gateway call, no fee, straight to fulfillment (same job a real
     * webhook would dispatch, just triggered from checkout instead).
     * payment_ref stays permanently null for an order settled this
     * way — a real, structural signal that no gateway was ever
     * involved, confirmed to never collide with
     * ReconcilePendingPaymentsCommand (its own query only ever selects
     * payment_status=pending, never Paid).
     */
    private function settleWithVoucher(Order $order, VoucherPreview $voucherPreview): Order
    {
        try {
            $this->vouchers->redeem(
                $voucherPreview->voucherId,
                $order->id,
                $order->voucher_discount,
                $order->customer_email,
                $order->customer_phone,
            );
        } catch (InvalidVoucherException $e) {
            $this->logAcceptedVoucherRedemptionRace($order, $e);
        }

        $this->decrementMembershipQuotaIfApplicable($order);

        FulfillOrderJob::dispatch($order->fresh());

        return $order->fresh();
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

        // ADR-024 decision #1: the voucher lock happens here, after the
        // gateway has confirmed a real, redirectable payment request
        // exists — never before creating the Order or before this
        // point. A gateway failure above (the `! $payment->success`
        // branch) never reaches this line, so a failed createPayment()
        // call never touches the voucher's remaining balance at all —
        // nothing to restore for that failure mode. Reachable from both
        // initiate() and resume(), so voucher_id is read off the Order
        // itself rather than threaded through as a separate parameter.
        if ($order->voucher_id !== null) {
            try {
                $this->vouchers->redeem(
                    $order->voucher_id,
                    $order->id,
                    $order->voucher_discount,
                    $order->customer_email,
                    $order->customer_phone,
                );
            } catch (InvalidVoucherException $e) {
                $this->logAcceptedVoucherRedemptionRace($order, $e);
            }
        }

        $this->decrementMembershipQuotaIfApplicable($order);

        return $order->fresh();
    }

    /**
     * ADR-024's accepted residual race: preview() (unlocked) validated
     * this voucher moments ago, but redeem()'s locked re-check failed —
     * only reachable if a second, near-simultaneous order from the
     * same customer (the voucher's ownership lock rules out anyone
     * else) drained the same voucher first. By this point the customer
     * has already been charged (or the order already marked Paid) at
     * the discounted amount; ADR-004 forbids clawing that back, so
     * fulfillment always proceeds regardless. Logged at error level so
     * it surfaces for admin/ledger reconciliation rather than passing
     * silently, matching this codebase's existing "escalate the
     * genuinely ambiguous case to a human" convention
     * (ReconcilePendingPaymentsCommand::flagIfStale()).
     */
    private function logAcceptedVoucherRedemptionRace(Order $order, InvalidVoucherException $e): void
    {
        Log::error('Voucher redemption failed after the order was already committed to its discounted price', [
            'order_number' => $order->order_number,
            'voucher_id' => $order->voucher_id,
            'error' => $e->getMessage(),
        ]);
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

    /**
     * ADR-027 Phase 6 (its 2026-08-29 continued addendum, decisions
     * 4/5/11): an unlocked read, deciding which price to charge — never
     * the locked commit (that's decrementMembershipQuotaIfApplicable()
     * below, at the same trust point VoucherService::redeem() already
     * uses). Returns null (standard pricing applies) for three reasons
     * treated identically, matching this codebase's "one generic
     * outcome, don't let the caller distinguish why" discipline
     * (VoucherService::assertUsable()'s own precedent): no membership
     * resolved at all, the membership's plan somehow missing (defensive
     * only — restrictOnDelete makes this unreachable in practice), or
     * quota insufficient for this order (the confirmed 2026-08-29
     * fallback — checkout is never blocked over it).
     */
    private function resolveMemberPricing(CheckoutRequest $request): ?MemberPricingResolution
    {
        if ($request->membershipId === null) {
            return null;
        }

        $membership = Membership::query()->with('membershipPlan')->find($request->membershipId);

        if ($membership === null || $membership->membershipPlan === null) {
            return null;
        }

        $discountPercent = (float) $membership->membershipPlan->discount_percent;
        $memberPriceSen = $this->membershipPricing->calculateMemberPrice(
            $request->costPriceSen,
            $request->packageMarkupPercent,
            $discountPercent,
        );

        if ($memberPriceSen > $membership->quota_remaining_sen) {
            return null;
        }

        return new MemberPricingResolution($membership->id, $memberPriceSen, $discountPercent);
    }

    /**
     * The locked commit — same trust point VoucherService::redeem()
     * already uses (after the gateway confirms success, or immediately
     * for a full-cover order), reached from both settleWithVoucher()
     * and requestPayment(). A `false` result (lost the race against a
     * concurrent order from the same member, quota already spent
     * elsewhere) is logged, never clawed back — the charge already
     * happened (ADR-004).
     */
    private function decrementMembershipQuotaIfApplicable(Order $order): void
    {
        if ($order->membership_id === null) {
            return;
        }

        $succeeded = $this->membershipQuota->decrement($order->membership_id, $order->id, $order->selling_price);

        if (! $succeeded) {
            Log::error('Membership quota decrement failed after the order was already committed at the member price', [
                'order_number' => $order->order_number,
                'membership_id' => $order->membership_id,
                'amount_sen' => $order->selling_price,
            ]);
        }
    }
}
