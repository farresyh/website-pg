<?php

namespace App\Models\Concerns;

use BackedEnum;

/**
 * A checkout attempt (wallet top-up, membership subscription) leaves
 * `pending` exactly once (item 63, 2026-10-04). Every Failed/Expired
 * write — CHIP webhook, reconcile commands, gateway-call failure — goes
 * through this one conditional UPDATE, so an answer read before a Paid
 * one committed can never overwrite it. Paid itself is written by the
 * services' own locked completion (completeTopup / completePaidAttempt),
 * which honours a late payment.
 */
trait LeavesPendingOnce
{
    public function leavePending(BackedEnum $status): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', 'pending')
            ->update(['status' => $status->value]);

        if ($updated === 0) {
            return false;
        }

        $this->status = $status;
        $this->syncOriginalAttribute('status');

        return true;
    }
}
