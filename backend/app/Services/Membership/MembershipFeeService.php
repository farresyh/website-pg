<?php

namespace App\Services\Membership;

use App\Jobs\SendMembershipReceiptJob;
use App\Models\Membership;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-027 continued addendum decision 15, grilled 2026-08-29 — the
 * single seam by which a membership-fee payment becomes a real
 * membership. Two callers, one seam: the admin "Record Payment" action
 * (`$adminUserId` set, `$reason` admin-typed) and — since ADR-068 —
 * the self-serve CHIP-checkout webhook / reconcile sweep
 * (`MembershipSubscriptionService`, `$adminUserId` null, `$reason`
 * null → derived). The two paths can never drift apart because they
 * share this method.
 *
 * Grill-pinned behaviour (see the ADR-027 2026-08-29 addendum for the
 * full reasoning):
 *
 * - Quota is NOT touched on a mid-cycle renewal (Q2): renewal extends
 *   `expires_at` by 30 days only; quota refills at the rolling 30-day
 *   cycle boundary, handled by ResetMembershipCyclesCommand. A lapsed
 *   membership (`expires_at` in the past) is instead reactivated with a
 *   fresh cycle + full quota (Q8).
 * - The fee amount is the caller's value (Q4): admin-typed (pre-filled
 *   from `plan.fee_sen`, adjustable with a required reason on deviation)
 *   on the admin path; server-computed `fee_sen` (ADR-068 decision 4 —
 *   never client-supplied) on the self-serve path. Booked to the ledger
 *   as a positive `membership_fee` credit (income into the platform
 *   owner).
 * - Plan change on renewal (Q5): `membership_plan_id` is always set to
 *   the submitted plan, whatever the current tier.
 * - Idempotency (Q11): `idempotency_key` + the fee-records unique index
 *   mirror ADR-035's voucher pattern — a double-submit can never
 *   double-book a fee.
 *
 * Concurrency note: two unique constraints can fire here, and each has
 * its own recovery. `memberships.(reseller_id, email)` (a brand-new
 * member created by a concurrent record — ADR-061 decision 5 made this
 * per-brand) is caught *inside* the transaction — the blocked
 * INSERT surfaces the violation only after the winner committed, so a
 * re-read sees the row and falls through to extend/reactivate. The fee
 * records' own `idempotency_key` (the true double-submit serialization
 * point) is left to propagate *out* — the whole transaction rolls back
 * (undoing any premature extend) and the caller returns the winner's
 * already-booked membership. The membership row is locked with
 * `lockForUpdate()` only once it is guaranteed to exist, avoiding a
 * gap-lock deadlock a lock-on-a-missing-email would otherwise cause.
 */
final class MembershipFeeService
{
    public const CYCLE_DAYS = 30;

    public function __construct(private readonly LedgerService $ledger) {}

    public function recordFeePaid(
        int $resellerId,
        string $email,
        int $planId,
        int $amountSen,
        ?int $adminUserId,
        ?string $reason,
        string $idempotencyKey,
    ): Membership {
        // Fast path (Q11): a replay of an already-recorded payment
        // returns the membership that payment activated/extended, never
        // a second ledger entry — and never a second receipt email
        // (ADR-068 S12: the mail dispatch below is only reached on a
        // genuine create/transition).
        $existingRecord = MembershipFeeRecord::query()
            ->with('membership')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingRecord !== null) {
            return $existingRecord->membership;
        }

        $plan = MembershipPlan::query()->findOrFail($planId);

        try {
            /** @var array{0: Membership, 1: string} $result */
            $result = DB::transaction(function () use ($resellerId, $email, $plan, $amountSen, $adminUserId, $reason, $idempotencyKey) {
                $membership = Membership::query()
                    ->where('reseller_id', $resellerId)
                    ->where('email', $email)
                    ->first();
                $isNew = false;

                if ($membership === null) {
                    try {
                        $membership = Membership::query()->create([
                            'reseller_id' => $resellerId,
                            'email' => $email,
                            'membership_plan_id' => $plan->id,
                            'status' => MembershipStatus::Active,
                            'cycle_started_at' => now(),
                            'quota_remaining_sen' => $plan->quota_sen,
                            'expires_at' => now()->addDays(self::CYCLE_DAYS),
                        ]);
                        $isNew = true;
                    } catch (UniqueConstraintViolationException) {
                        // (reseller_id, email) race: a concurrent record
                        // created this pair. The blocked INSERT surfaced
                        // the violation only after that transaction
                        // committed — but the plain snapshot read above
                        // can't see it under REPEATABLE READ, so re-read
                        // with a CURRENT read (`lockForUpdate()`) which
                        // always sees committed data, and hold that lock
                        // through the transition.
                        $membership = Membership::query()
                            ->where('reseller_id', $resellerId)
                            ->where('email', $email)
                            ->lockForUpdate()
                            ->first();
                    }
                } else {
                    $membership = Membership::query()->where('id', $membership->id)->lockForUpdate()->first();
                }

                $transition = $isNew
                    ? 'subscription'
                    : $this->applyTransition($membership, $plan);

                // ADR-068 S12: the system (self-serve) path carries no
                // admin-typed reason — derive one that names what
                // happened, so /admin/membership's Fee Payments view and
                // the ledger can tell a fresh subscription from a
                // renewal. The admin path's own `reason` is untouched.
                $effectiveReason = ($adminUserId === null && $reason === null)
                    ? "self-serve {$transition}"
                    : $reason;

                $this->bookFee($membership, $plan, $amountSen, $adminUserId, $effectiveReason, $idempotencyKey);

                return [$membership, $transition];
            });
        } catch (UniqueConstraintViolationException) {
            // Idempotency-key race: a concurrent duplicate already booked
            // this key. Its transaction committed before the blocked
            // INSERT surfaced the violation, so the record is visible now.
            return MembershipFeeRecord::query()
                ->with('membership')
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail()
                ->membership;
        }

        [$membership, $transition] = $result;

        // ADR-068 decision 9 — the receipt goes out on every genuine
        // create/transition, admin-recorded or self-serve alike, after
        // the transaction commits and off the request thread
        // (backend/AGENTS.md: never mail inline on a webhook path).
        SendMembershipReceiptJob::dispatch($membership->id, $transition, $amountSen);

        return $membership;
    }

    /**
     * @return 'renewal'|'reactivation'
     */
    private function applyTransition(Membership $membership, MembershipPlan $plan): string
    {
        if ($membership->expires_at === null || $membership->expires_at->isPast()) {
            // Q8 reactivation: lapsed/expired member pays again — same
            // row flips back to Active with a fresh cycle and full quota
            // (order-history linkage by email survives).
            $membership->update([
                'membership_plan_id' => $plan->id,
                'status' => MembershipStatus::Active,
                'cycle_started_at' => now(),
                'quota_remaining_sen' => $plan->quota_sen,
                'expires_at' => now()->addDays(self::CYCLE_DAYS),
            ]);

            return 'reactivation';
        }

        // Q1/Q2 active renewal: extend the paid-through date; quota and
        // cycle_started_at are deliberately untouched (quota refills at
        // the cycle boundary, not on payment).
        $membership->update([
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Active,
            'expires_at' => $membership->expires_at->addDays(self::CYCLE_DAYS),
        ]);

        return 'renewal';
    }

    private function bookFee(
        Membership $membership,
        MembershipPlan $plan,
        int $amountSen,
        ?int $adminUserId,
        ?string $reason,
        string $idempotencyKey,
    ): void {
        $ledgerEntry = $this->ledger->credit(
            LedgerOwnerType::Platform,
            null,
            $amountSen,
            'membership_fee',
            'membership',
            $membership->id,
            $adminUserId,
            $reason,
        );

        MembershipFeeRecord::query()->create([
            'membership_id' => $membership->id,
            'membership_plan_id' => $plan->id,
            'amount_sen' => $amountSen,
            'ledger_entry_id' => $ledgerEntry->id,
            'admin_user_id' => $adminUserId,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
