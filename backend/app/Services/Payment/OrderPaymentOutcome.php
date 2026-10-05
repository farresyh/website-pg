<?php

namespace App\Services\Payment;

/** What OrderPaymentOutcomeService did with one gateway answer. */
enum OrderPaymentOutcome
{
    /** Pending → Paid, FulfillOrderJob dispatched. */
    case Fulfilling;

    /** Paid after a failure or a compensation: recorded, sent to NeedsReview, never fulfilled. */
    case FlaggedForReview;

    /** Pending → Failed, reserved voucher and quota given back. */
    case Failed;

    /** Paid amount differs from final_amount: nothing changed. */
    case AmountMismatch;

    /** Already in the state this answer would produce (a repeat delivery). */
    case AlreadyProcessed;
}
