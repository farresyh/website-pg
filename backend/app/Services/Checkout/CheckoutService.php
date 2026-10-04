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
use App\Services\Pricing\CheckoutTotal;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherPreview;
use App\Services\Voucher\VoucherService;
use App\Support\StorefrontBrand;
use Illuminate\Support\Facades\DB;
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
        private readonly OrderPricingResolver $pricingResolver,
        private readonly CheckoutTotalService $checkoutTotal,
        private readonly OrderFactory $orderFactory,
        private readonly VoucherService $vouchers,
        private readonly MembershipQuotaService $membershipQuota,
        private readonly StorefrontBrand $storefrontBrand,
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
        $pricing = $this->pricingResolver->resolveStorefront(
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
            $request->affiliateId,
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
                // M-6, 2026-09-29 audit: a full-cover order used to be
                // stamped Paid right here, before the voucher's own
                // redeem() lock ever ran — settleWithVoucher() below is
                // now the real commit checkpoint (mirrors decision #1's
                // "lock only after the gateway confirms success", with
                // redeem() itself standing in for the gateway since a
                // full-cover order never calls one).
                paymentStatus: PaymentStatus::Pending,
                paidAt: null,
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
            return $this->settleWithVoucher($order);
        }

        return $this->requestPayment(
            $order,
            $gateway,
            channelCode: $request->channelCode,
            channelProperties: $request->channelProperties,
        );
    }

    /**
     * The idempotent-replay path for an Order that already exists (found
     * by CheckoutController via checkout_idempotency_key). Without a
     * payment_ref, the previous attempt's gateway call failed or the
     * process died before recording it: retry the payment leg, reusing
     * the Order's snapshotted pricing (ORD-9) and its order_number as the
     * gateway `reference`, so the gateway's own reference dedupe still
     * applies. With one, the link already exists.
     *
     * Either way, ADR-024 2026-10-04 addendum decision 6: the order is
     * reserved before any link goes back out (a no-op if it already is),
     * and a Failed order is refused — a replay must never hand out the
     * link of an attempt that lost its voucher/quota reservation.
     */
    public function resume(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties = []): Order
    {
        if ($order->payment_status === PaymentStatus::Failed) {
            throw CheckoutAttemptClosedException::alreadyFailed();
        }

        if ($order->payment_ref === null) {
            return $this->requestPayment($order, $gateway, $channelCode, $channelProperties);
        }

        return $this->reserveOrClose($order);
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
     *
     * M-6, 2026-09-29 audit (ADR-024 addendum): `redeem()` is this
     * branch's real commit checkpoint, unlike the accepted residual
     * race `requestPayment()` below still logs-and-proceeds through —
     * there, real money (or a real gateway payment request) already
     * exists by the time `redeem()` could lose, so ADR-004 forbids
     * clawing it back. Here, nothing has moved yet: the Order is still
     * Pending and `FulfillOrderJob` hasn't been dispatched, so a lost
     * race can — and must — fail the checkout outright instead of
     * shipping real goods for an unpaid, unbacked order. Otherwise N
     * concurrent requests citing the same exactly-covering voucher
     * would each pass `preview()` and each get fulfilled, repeatably,
     * bounded only by how many an attacker sends.
     *
     * ADR-024 2026-10-04 addendum decision 4: member quota is reserved
     * here too, before Paid, under the same rule.
     */
    private function settleWithVoucher(Order $order): Order
    {
        $lost = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $this->reserveAll($locked)) {
                $locked->update(['payment_status' => PaymentStatus::Failed->value]);

                return true;
            }

            $locked->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

            return false;
        });

        if ($lost) {
            $this->logReservationLost($order);

            throw CheckoutAttemptClosedException::reservationLost();
        }

        FulfillOrderJob::dispatch($order->fresh());

        return $order->fresh();
    }

    /**
     * M-7, 2026-09-29 audit: two concurrent calls for the same Order
     * (double-click retry, two tabs, a client-side timeout retry racing
     * the still-in-flight first attempt) used to both read
     * payment_ref===null and both call $gateway->createPayment() — CHIP's
     * own reference-dedupe behaviour is unconfirmed (see the comment on
     * initiate() above), so this could mint two live purchases for one
     * order; whichever update() committed last silently discarded the
     * other's payment_request_id, orphaning it (unpayable back to this
     * order once ChipWebhookController's strict payment_ref match misses).
     * Locked and re-checked exactly like OrderFulfillmentService's own
     * defense-in-depth pattern, at the cost of holding this lock across
     * the live gateway call — deliberately different from initiate()'s
     * own "never wrap the gateway call in a transaction" rule above,
     * which is about not risking a rolled-back Order INSERT; this Order
     * already exists and is already committed, so a transaction failure
     * here only reproduces the pre-existing "stuck at Pending, retryable"
     * failure mode, never a worse one. A retry-storm on this path isn't
     * expected (a customer-facing single-order retry, not a queued job),
     * so the held-lock cost is accepted rather than building a separate
     * reservation column.
     */
    private function requestPayment(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties): Order
    {
        [$order, $created] = DB::transaction(function () use ($order, $gateway, $channelCode, $channelProperties) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // 2026-09-29 pre-release review: a full-cover-by-voucher order
            // (final_amount 0) never has a gateway leg — since M-6 it can
            // sit at Pending/Failed with payment_ref null, and a replayed
            // idempotency key must not send it to CHIP as a RM0 purchase.
            if ($locked->payment_ref !== null || $locked->final_amount === 0) {
                return [$locked, false];
            }

            return [$this->createPaymentFor($locked, $gateway, $channelCode, $channelProperties), true];
        });

        // 2026-09-29 pre-release review: reservations run AFTER payment_ref
        // commits, never in its transaction — an unexpected failure in the
        // voucher/quota lock (lock-wait timeout, deadlock) used to roll back
        // payment_ref too, orphaning a live CHIP purchase the webhook's
        // strict payment_ref match could never find. Every caller reserves,
        // not just the one that created the link: reserveOrClose() is
        // idempotent under the Order lock, and no caller may return a link
        // the order hasn't reserved for (ADR-024 2026-10-04 addendum).
        return $this->reserveOrClose($order);
    }

    private function createPaymentFor(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties): Order
    {
        // The storefront can't put order_number in the return URLs it
        // sends with the checkout request — it doesn't have one yet at
        // that point (this Order didn't exist until just above/earlier
        // in initiate()). Now that it does, overwrite whatever generic
        // URL the storefront sent with the real per-order tracking page,
        // so a redirect-based channel (FPX, some e-wallets) lands the
        // customer straight on their own order's status instead of the
        // general "look up an order" search page.
        //
        // Bug fix, 2026-09-24: this MUST be built from `StorefrontBrand`'s
        // own request-scoped origin, not the platform default — an order
        // placed on an affiliate's custom domain (e.g. fixfastapp.com)
        // otherwise redirected to pekangame.space/order/status/..., whose
        // /track-order is itself brand-scoped and 404s on that order.
        $orderStatusUrl = $this->storefrontBrand->url()
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
            // Item 39 (2026-09-27 money-critical audit): the order_number
            // is already carried separately as `reference` above — this is
            // the line-item name CHIP shows the customer on its checkout
            // page/receipt, so it should describe what they're buying, not
            // repeat the reference.
            description: $order->package?->name ?? $order->game?->name ?? "Order {$order->order_number}",
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

        return $order;
    }

    /**
     * ADR-024 2026-10-04 addendum, decisions 1 and 5: reserve the
     * voucher and member quota for an order whose payment link exists,
     * or close the attempt. Reservation still runs after the gateway
     * confirmed the link (decision 1 of the base ADR — a gateway failure
     * never touches either balance), but a lost race is no longer
     * logged-and-accepted: at this point the customer has paid nothing
     * and has not even received the link, so refusing costs nothing.
     *
     * The whole check-reserve-or-fail runs in one transaction holding
     * the Order row lock, separate from the payment_ref transaction.
     * redeem()/decrement() treat an existing row for the order as
     * success even once it was restored, so the Order lock is what lets
     * a concurrent replay see the final state rather than a half-undone
     * reservation. Lock order Order → Voucher → Membership, matching
     * OrderSettlementService and the CHIP webhook.
     */
    private function reserveOrClose(Order $order): Order
    {
        [$order, $lost] = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Paid (a successful replay) or no link yet (full-cover, or a
            // gateway call that failed): nothing to reserve here.
            if ($locked->payment_status !== PaymentStatus::Pending || $locked->payment_ref === null) {
                return [$locked, false];
            }

            if ($this->reserveAll($locked)) {
                return [$locked, false];
            }

            $locked->update(['payment_status' => PaymentStatus::Failed->value]);

            return [$locked, true];
        });

        if ($order->payment_status === PaymentStatus::Failed && ! $lost) {
            throw CheckoutAttemptClosedException::alreadyFailed();
        }

        if ($lost) {
            $this->logReservationLost($order);

            throw CheckoutAttemptClosedException::reservationLost();
        }

        return $order->fresh();
    }

    /**
     * Reserves every instrument the order was priced with — voucher
     * first, then member quota — or reserves nothing (decision 3: a
     * voucher that won is given back when the quota loses). Callers
     * hold the Order row lock. Idempotent per order: both services
     * no-op on an order that already has its row.
     */
    private function reserveAll(Order $order): bool
    {
        if ($order->voucher_id !== null) {
            try {
                $this->vouchers->redeem(
                    $order->voucher_id,
                    $order->id,
                    $order->voucher_discount,
                    $order->customer_email,
                    $order->customer_phone,
                    $order->affiliate_id,
                );
            } catch (InvalidVoucherException) {
                return false;
            }
        }

        if ($order->membership_id !== null
            && ! $this->membershipQuota->decrement($order->membership_id, $order->id, $order->selling_price)) {
            $this->vouchers->restore($order->id);

            return false;
        }

        return true;
    }

    /**
     * Not an error: the order failed closed and nothing was given away.
     * Kept at warning with the order and both instruments, since a burst
     * from one customer is the abuse signal (addendum, consequence 1).
     */
    private function logReservationLost(Order $order): void
    {
        Log::warning('Checkout reservation lost — order failed closed', [
            'order_number' => $order->order_number,
            'voucher_id' => $order->voucher_id,
            'membership_id' => $order->membership_id,
        ]);
    }

    /**
     * ADR-068 decision 16 — the membership's own OTP-verified email,
     * looked up independently of member *pricing* (that decision lives in
     * OrderPricingResolver now). `CheckoutController::
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
        ?int $affiliateId = null,
    ): array {
        // ADR-024 decision #3: resolved server-side from the voucher's
        // own stored code/remaining/ownership — never a client-
        // submitted discount amount (ORD-9). preview() only reads, it
        // never locks or mutates — the real, locked spend happens
        // later, in requestPayment()/settleWithVoucher() below,
        // matching decision #1's exact timing.
        // ADR-060 PR-4d: $affiliateId is the Host-resolved storefront
        // brand — a voucher issued on another brand is rejected here.
        $voucherPreview = $voucherCode !== null
            ? $this->vouchers->preview($voucherCode, $customerEmail, $customerPhone, $sellingPriceForOrder, $affiliateId)
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
        ?int $affiliateId = null,
    ): CheckoutTotalPreview {
        $pricing = $this->pricingResolver->resolveStorefront(
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
            $affiliateId,
        );

        return new CheckoutTotalPreview(
            sellingPriceSen: $pricing->sellingPriceSen,
            memberDiscountPercent: $pricing->memberDiscountPercent,
            voucherDiscountSen: $total->voucherDiscount,
            transactionFeeSen: $fullyCoveredByVoucher ? 0 : $total->transactionFee,
            finalAmountSen: $fullyCoveredByVoucher ? 0 : $total->finalAmount,
        );
    }
}
