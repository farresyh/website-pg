<?php

namespace App\Services\Membership;

use App\Models\Membership;
use App\Models\MembershipQuotaDebit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-027 base decision 7 / Phase 6 (its 2026-08-29 continued addendum):
 * quota is tracked and consumed in member-price-paid currency
 * (`Membership.quota_remaining_sen`), a directly-decrementable balance
 * column — same shape as `Voucher.remaining`, not `LedgerAccount`'s
 * mutex-only role, so this mirrors `VoucherService::redeem()`'s exact
 * two-layer pattern: an existence pre-check (fast path) plus a real,
 * locked commit guarded by `membership_quota_debits.order_id`'s unique
 * index — the actual serialization point against a genuine concurrent
 * double-call for the same order (`CheckoutService::requestPayment()`
 * is reachable from both `initiate()` and `resume()`).
 *
 * A separate, unlocked read (`Membership::quota_remaining_sen` read
 * directly by the caller) is used earlier, at pricing-decision time, to
 * choose member vs. standard pricing — this method only guards the
 * actual commit. `false` here (insufficient quota at commit time, lost
 * a genuine race against a concurrent order from the same member) is
 * accepted, never clawed back — see CheckoutService's own handling,
 * mirroring its existing voucher-redemption-race acceptance (ADR-004:
 * no cash refunds, the charge already happened).
 */
final class MembershipQuotaService
{
    public function decrement(int $membershipId, int $orderId, int $amountSen): bool
    {
        if (MembershipQuotaDebit::query()->where('order_id', $orderId)->exists()) {
            return true;
        }

        try {
            return DB::transaction(function () use ($membershipId, $orderId, $amountSen) {
                $membership = Membership::query()->lockForUpdate()->findOrFail($membershipId);

                if ($membership->quota_remaining_sen < $amountSen) {
                    return false;
                }

                $membership->decrement('quota_remaining_sen', $amountSen);

                MembershipQuotaDebit::query()->create([
                    'order_id' => $orderId,
                    'membership_id' => $membershipId,
                    'amount_sen' => $amountSen,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a genuine race against a concurrent duplicate
            // decrement call for the same order — this call's own
            // transaction already rolled back, so nothing was
            // double-spent. The winning call's debit row is what
            // stands; not a failure from this caller's point of view.
            return true;
        }
    }

    /**
     * M-9, 2026-09-29 audit: quota spent at CHIP payment-link creation
     * (`CheckoutService::requestPayment()`) was never given back on a
     * failed/abandoned checkout. No-op if this order never actually
     * debited quota (most orders) or was already restored, so every
     * restore-trigger call site (`ChipWebhookController`'s Failed branch,
     * `PaymentReconciliationService::markFailed()`) can invoke this
     * unconditionally, mirroring `VoucherService::restore()`.
     *
     * `created_at >= cycle_started_at` guards against `Membership`'s own
     * 30-day cycle reset (ADR-027 decision 7: quota refills to the plan's
     * full amount at the boundary) having already run between the debit
     * and this restore — crediting a stale debit back on top of an
     * already-refilled balance would over-grant quota past the plan's
     * cap. No test covers this edge directly (a 30-min reconcile window
     * can't practically straddle a 30-day boundary), but the guard costs
     * nothing to keep.
     */
    public function restore(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $debit = MembershipQuotaDebit::query()
                ->where('order_id', $orderId)
                ->whereNull('restored_at')
                ->lockForUpdate()
                ->first();

            if ($debit === null) {
                return;
            }

            $membership = Membership::query()->lockForUpdate()->findOrFail($debit->membership_id);

            if ($debit->created_at->lt($membership->cycle_started_at)) {
                $debit->update(['restored_at' => now()]);

                return;
            }

            $membership->increment('quota_remaining_sen', $debit->amount_sen);
            $debit->update(['restored_at' => now()]);
        });
    }
}
