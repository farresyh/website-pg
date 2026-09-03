<?php

namespace App\Services\Membership;

/**
 * ADR-068 decision 2 — the lifecycle of one self-serve membership
 * checkout attempt.
 *
 *  - `Pending`  created; a CHIP purchase exists (or, briefly, the
 *               gateway call is still in flight).
 *  - `Paid`     the webhook (or the reconcile sweep) confirmed payment
 *               and `MembershipFeeService::recordFeePaid()` ran.
 *  - `Failed`   the gateway rejected `createPayment()` (S3), or CHIP
 *               reported the purchase as errored/cancelled.
 *  - `Expired`  never paid within the reconcile window — abandoned.
 */
enum MembershipCheckoutAttemptStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
