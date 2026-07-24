<?php

namespace App\Services\Withdrawal;

/**
 * WTH-3/§7.4. Pending -> Approved -> Completed, or Pending -> Rejected.
 * Reject is only valid from Pending for MVP — an Approved withdrawal
 * already has its ledger debit written, and reversing that is a
 * deliberately deferred edge case (manual 'adjustment' entry instead).
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
}
