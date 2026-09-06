<?php

namespace App\Services\Checkout;

use App\Jobs\FulfillOrderJob;
use App\Models\Membership;
use App\Models\Order;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DuplicateOrderException;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderFactory;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutPricingResolver;
use App\Services\Pricing\CheckoutTotal;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherPreview;
use App\Services\Voucher\VoucherService;
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
        private readonly CheckoutPricingResolver $pricingResolver,
        private readonly CheckoutTotalService $checkoutTotal,
        private readonly OrderFactory $orderFactory,
        private readonly VoucherService $vouchers,
        private readonly MembershipQuotaService $membershipQuota,
    ) {}

    /**
     * Deliberately NOT wrapped in one DB transaction spanning the
     * payment-gateway call: the Order is created and committed FIRST.
     * If the gateway call then fails, or the connection drops right
     * after it succeeds, the failure mode is the safe direction — an
     * Order stuck at Pending with no payment_ref, recoverable by
     * retry — rather than the dangerous direction a wrapping
     * transaction would risk: a real, payable CHIP purchase link
     * existing with no matching Order anywhere in the system if the
     * transaction rolled back after CHIP had already accepted it.
     *
     * $gateway is caller-resolved (CheckoutController looks up the
     * matched PaymentMethod row's `gateway` column via
     * PaymentGatewayFactory) rather than constructor-injected — kept
     * this shape through ADR-022's 2026-09-01 addendum even though CHIP
     * is the only gateway, so a future multi-region ADR that re-adds a
     * second one needs no change here (see the payment_methods
     * migration's doc comment).
     */
    public function initiate(CheckoutRequest $request, PaymentGateway $gateway): Order
    {
        $pricing = $this->pricingResolver->resolve(
            $request->costPriceSen,
            $request->standardSellingPriceSen,
            $request->packageMarkupPercent,
            $request->affiliateMarkupPct,
            $request->tierMarkupPct,
            $request->membershipId,
        );

        // ADR-068 decision 16: a logged-in member's order is attributed
        // to their OTP-verified membership email, never a contact address
        // typed into checkout — identity is not trusted from the client
        // (the ORD-9 principle). Resolved from the membership id alone, so
        // an out-of-quota member (standard-priced fallback,
        // $pricing->membershipId === null) still gets their email bound.
        // Name and phone stay as typed — a member legitimately tops up for
        // other people.
        $customerEmail = $this->resolveMemberEmail($request->membershipId) ?? $request->customerEmail;

        [$voucherPreview, $total, $fullyCoveredByVoucher] = $this->computeTotal(
            $pricing->sellingPriceSen,
            $request->voucherCode,
            $customerEmail,
            $request->customerPhone,
            $request->paymentFeeConfig,
        );

        // ADR-019 idempotency finding: the checkout path never relies on
        // a gateway-side idempotency-key header. A retried gateway call
        // *within* this one initiate() reuses the same order_number as
        // its reference, so a gateway that dedupes on reference (CHIP's
        // `reference` field — verify the exact collision behaviour via
        // app:chip-smoke-test before trusting it) won't create a second
        // live purchase. The gap that didn't close on its own — a
        // retried POST /api/checkout HTTP request (customer double-click,
        // client-side timeout retry) calling initiate() again from
        // scratch with a brand-new order_number each time — is closed by
        // $request->idempotencyKey below: stamped onto the Order at
        // creation (not after payment succeeds), under a DB-level unique
        // constraint, so a genuinely concurrent duplicate request fails
        // fast at the INSERT rather than ever reaching the gateway a
        // second time.
        try {
            $order = $this->orderFactory->create(new OrderDraft(
                pricing: $pricing,
                idempotencyKey: $request->idempotencyKey,
                customerEmail: $customerEmail,
                customerName: $request->customerName,
                customerPhone: $request->customerPhone,
                playerId: $request->playerId,
                serverId: $request->serverId,
                affiliateId: $request->affiliateId,
                paymentStatus: $fullyCoveredByVoucher ? PaymentStatus::Paid : PaymentStatus::Pending,
                paidAt: $fullyCoveredByVoucher ? now() : null,
                paymentMethod: $request->paymentMethod,
                gameId: $request->gameId,
                packageId: $request->packageId,
                supplierId: $request->supplierId,
                supplierProductRef: $request->supplierProductRef,
                voucherId: $voucherPreview?->voucherId,
                voucherDiscountSen: $total->voucherDiscount,
                transactionFeeSen: $fullyCoveredByVoucher ? 0 : $total->transactionFee,
                finalAmountSen: $fullyCoveredByVoucher ? 0 : $total->finalAmount,
                affiliateMarkupPct: $request->affiliateMarkupPct,
                paymentGateway: $request->paymentGateway,
                channelCode: $request->channelCode,
            ));
        } catch (DuplicateOrderException $e) {
            throw new DuplicateCheckoutAttemptException(
                "Duplicate checkout attempt for idempotency key {$request->idempotencyKey}",
                previous: $e,
            );
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
     * order_number as the gateway `reference`, same as a fresh
     * initiate() would, so the gateway's own reference dedupe still
     * applies if that earlier attempt actually reached the gateway
     * despite failing to persist locally.
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
            .'/order/status/'.$order->order_number;

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
            description: "PekanGame order {$order->order_number}",
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
     * ADR-068 decision 16 — the membership's own OTP-verified email,
     * looked up independently of member *pricing* (that decision lives in
     * CheckoutPricingResolver now). `CheckoutController::
     * resolveMembershipId()` only ever returns an id for an Active,
     * unexpired membership on this brand, so a non-null id here is a
     * genuine logged-in member; a lapsed/absent session leaves the
     * client-typed email untouched (guest behaviour, ADR-011).
     */
    private function resolveMemberEmail(?int $membershipId): ?string
    {
        if ($membershipId === null) {
            return null;
        }

        return Membership::query()->whereKey($membershipId)->value('email');
    }

    /**
     * The voucher-preview + fee/total computation shared by initiate()
     * and previewTotal() — a single seam so the two can never drift
     * apart on the actual formula. Returns [VoucherPreview|null,
     * CheckoutTotal, bool $fullyCoveredByVoucher].
     *
     * @return array{0: ?VoucherPreview, 1: CheckoutTotal, 2: bool}
     */
    private function computeTotal(
        int $sellingPriceForOrder,
        ?string $voucherCode,
        string $customerEmail,
        ?string $customerPhone,
        PaymentMethodFeeConfig $paymentFeeConfig,
    ): array {
        // ADR-024 decision #3: resolved server-side from the voucher's
        // own stored code/remaining/ownership — never a client-
        // submitted discount amount (ORD-9). preview() only reads, it
        // never locks or mutates — the real, locked spend happens
        // later, in requestPayment()/settleWithVoucher() below,
        // matching decision #1's exact timing.
        $voucherPreview = $voucherCode !== null
            ? $this->vouchers->preview($voucherCode, $customerEmail, $customerPhone, $sellingPriceForOrder)
            : null;

        $total = $this->checkoutTotal->calculate(
            $sellingPriceForOrder,
            $voucherPreview?->discountSen ?? 0,
            $paymentFeeConfig,
        );

        // ADR-024 decision #5: a voucher covering the full price means
        // feeBase is 0 — CheckoutTotalService's own transactionFee
        // formula would still apply a payment method's flat-fee
        // component even at feeBase=0 (only the percentage component
        // scales with the base), which makes no sense for an order
        // that never touches a payment gateway at all. Forced to 0
        // here rather than trusting that formula for this branch.
        $fullyCoveredByVoucher = $total->feeBase === 0 && $voucherPreview !== null;

        return [$voucherPreview, $total, $fullyCoveredByVoucher];
    }

    /**
     * Bug fix, 2026-08-30: the storefront's pre-payment Order Summary/
     * Review Modal only ever showed `package price - voucher discount`,
     * never the transaction fee — so the real charge (this exact
     * formula, via initiate() above) was always higher than what the
     * customer saw before clicking "Confirm & Pay" whenever the chosen
     * channel had a nonzero fee. Read-only: no Order, no gateway call,
     * no voucher lock (VoucherService::preview() itself never mutates).
     */
    public function previewTotal(
        int $costPriceSen,
        int $standardSellingPriceSen,
        float $packageMarkupPercent,
        float $affiliateMarkupPct,
        PaymentMethodFeeConfig $paymentFeeConfig,
        ?int $membershipId,
        ?string $voucherCode,
        string $customerEmail,
        ?string $customerPhone,
        ?float $tierMarkupPct = null,
    ): CheckoutTotalPreview {
        $pricing = $this->pricingResolver->resolve(
            $costPriceSen,
            $standardSellingPriceSen,
            $packageMarkupPercent,
            $affiliateMarkupPct,
            $tierMarkupPct,
            $membershipId,
        );

        [, $total, $fullyCoveredByVoucher] = $this->computeTotal(
            $pricing->sellingPriceSen,
            $voucherCode,
            $customerEmail,
            $customerPhone,
            $paymentFeeConfig,
        );

        return new CheckoutTotalPreview(
            sellingPriceSen: $pricing->sellingPriceSen,
            memberDiscountPercent: $pricing->memberDiscountPercent,
            voucherDiscountSen: $total->voucherDiscount,
            transactionFeeSen: $fullyCoveredByVoucher ? 0 : $total->transactionFee,
            finalAmountSen: $fullyCoveredByVoucher ? 0 : $total->finalAmount,
        );
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
