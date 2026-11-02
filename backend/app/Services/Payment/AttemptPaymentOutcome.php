<?php

namespace App\Services\Payment;

/**
 * What a checkout attempt's applyPaid() did with one "paid" answer from
 * the gateway (ADR-135 decision 11): a wallet top-up
 * (ResellerWalletService) or a membership subscription
 * (MembershipSubscriptionService). The order counterpart is OrderPaymentOutcome.
 */
enum AttemptPaymentOutcome
{
    /** Pending (or ended) → Paid: the balance was credited / the membership activated. */
    case Applied;

    /** Applied, but to a closed reseller: stranded money someone must send back (ADR-131 decision 6). */
    case AppliedToClosedAccount;

    /** Already Paid (a repeat delivery, or another path got there first). */
    case AlreadyProcessed;

    /** The paid amount differs from total_charged_sen, or is missing: nothing changed. */
    case AmountMismatch;
}
