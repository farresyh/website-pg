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
}
