<?php

namespace App\Services\Reseller;

/**
 * ADR-073 decision 3(a) / PR-G: mirrors `MembershipCheckoutAttemptStatus`
 * exactly — `pending -> paid/failed/expired`, one CHIP checkout attempt.
 */
enum WalletTopupAttemptStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
