<?php

namespace App\Services\Reseller;

/** ADR-135 decision 5: what a reseller's cancel of a pending top-up came to. */
enum WalletTopupCancelOutcome
{
    /** CHIP confirmed the purchase can no longer be paid; the attempt ended. */
    case Cancelled;

    /** CHIP (or our record) says it is paid: nothing changed here, the balance follows from the webhook or reconcile. */
    case AlreadyPaid;

    /** The attempt had already failed or expired. */
    case AlreadyEnded;

    /** CHIP says the payment is still in progress, or CHIP could not be asked: the attempt stays pending. */
    case InFlight;

    /** The CHIP purchase was never recorded (its create response was lost): it expires by itself. */
    case NoPurchaseYet;
}
