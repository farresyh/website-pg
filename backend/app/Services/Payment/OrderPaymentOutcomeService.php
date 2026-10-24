<?php

namespace App\Services\Payment;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Accounting\AccountingPeriod;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a gateway's terminal answer about an order is applied —
 * the CHIP webhook and PaymentReconciliationService (the scheduled sweep
 * and "Check from Gateway") both call it. Item 63, 2026-10-04: the two
 * used to carry their own copies, read the status unlocked and then
 * wrote; the webhook later gained the M-4 and Paid-after-Failed branches
 * and the reconcile copy never did.
 *
 * Every decision runs under the Order row lock and re-reads the status
 * there, so a stale caller can never act on a state another answer has
 * already moved past. Lock order Order → voucher → membership, matching
 * CheckoutService and OrderSettlementService.
 *
 * Only terminal answers change anything. A non-terminal one (Pending)
 * is never written: it used to be able to move a Failed order — voucher
 * and quota already given back — back to Pending, so a later Paid
 * skipped the NeedsReview branch and fulfilled at the discount.
 */
final class OrderPaymentOutcomeService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly MembershipQuotaService $membershipQuota,
        private readonly OrderStatusService $orderStatus,
    ) {}

    /**
     * The gateway confirmed payment of $amountSen. Pending → Paid and
     * fulfilment; after a failure or a compensation (M-4, ADR-102
     * 2026-09-29 addendum) → Paid but NeedsReview, never auto-fulfilled.
     */
    public function applyPaid(Order $order, ?int $amountSen, ?int $paidOn = null, ?int $feeSen = null): OrderPaymentOutcome
    {
        $outcome = DB::transaction(function () use ($order, $amountSen, $paidOn, $feeSen) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->payment_status === PaymentStatus::Paid) {
                return OrderPaymentOutcome::AlreadyProcessed;
            }

            // Defense-in-depth: payment_ref already binds the answer to one
            // fixed-amount purchase; a mismatch still never fulfils.
            if ($amountSen !== $locked->final_amount) {
                Log::error('Payment outcome rejected: amount mismatch', [
                    'order_number' => $locked->order_number,
                    'expected_sen' => $locked->final_amount,
                    'received_sen' => $amountSen,
                ]);

                return OrderPaymentOutcome::AmountMismatch;
            }

            if ($locked->payment_status === PaymentStatus::Failed || $locked->isAlreadyCompensated()) {
                Log::error('Paid after a failed payment or a compensation — flagged for manual review, not fulfilled', [
                    'order_number' => $locked->order_number,
                    'delivery_status' => $locked->delivery_status->value,
                ]);

                $attributes = ['payment_status' => PaymentStatus::Paid->value, ...$this->paidFacts($locked, $paidOn, $feeSen)];

                try {
                    $attributes['delivery_status'] = $this->orderStatus->markNeedsReview($locked->delivery_status)->value;
                } catch (InvalidOrderTransitionException) {
                    // Already NeedsReview, or Delivered by an unrelated
                    // resolution: still record the payment, leave delivery.
                }

                $locked->update($attributes);

                return OrderPaymentOutcome::FlaggedForReview;
            }

            $locked->update(['payment_status' => PaymentStatus::Paid->value, ...$this->paidFacts($locked, $paidOn, $feeSen)]);

            return OrderPaymentOutcome::Fulfilling;
        });

        if ($outcome === OrderPaymentOutcome::Fulfilling) {
            FulfillOrderJob::dispatch($order->fresh());
        }

        return $outcome;
    }

    /**
     * The gateway confirmed a terminal failure. Pending → Failed and the
     * reserved voucher and quota go back (ADR-024 decision 6a, M-9). A
     * Paid order is never touched.
     */
    public function applyFailed(Order $order): OrderPaymentOutcome
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->payment_status !== PaymentStatus::Pending) {
                return OrderPaymentOutcome::AlreadyProcessed;
            }

            $locked->update(['payment_status' => PaymentStatus::Failed->value]);
            $this->vouchers->restore($locked->id);
            $this->membershipQuota->restore($locked->id);

            return OrderPaymentOutcome::Failed;
        });
    }

    /**
     * ADR-127 F1/F2: `paid_at` is when CHIP accepted the payment, so a
     * late webhook or the reconciliation sweep no longer moves a 23:58
     * payment into the next day or month. Our own processing time is used
     * when CHIP sent no time, when its time falls outside the order's life
     * (before it was created, or in the future), or when it falls in a
     * closed month (decision 3).
     *
     * @return array{paid_at: Carbon, gateway_fee_sen: int|null}
     */
    private function paidFacts(Order $order, ?int $paidOn, ?int $feeSen): array
    {
        $paidAt = $paidOn === null ? null : Carbon::createFromTimestamp($paidOn, 'UTC');

        if ($paidAt === null || $paidAt->lt($order->created_at) || $paidAt->gt(now()) || AccountingPeriod::isClosed($paidAt)) {
            $paidAt = now();
        }

        return ['paid_at' => $paidAt, 'gateway_fee_sen' => $feeSen];
    }
}
