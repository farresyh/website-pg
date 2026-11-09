<?php

namespace App\Services\Rebate;

use App\Models\Reseller;
use App\Models\ResellerRebateEligibilityChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-139 decision 36: the one writer of `resellers.rebate_eligible`. Each
 * change is recorded. Turning it off removes the reseller from the live
 * figure and the month's finalise; claims are refused while it is off.
 */
final class RebateEligibilityService
{
    public function set(Reseller $reseller, bool $eligible, int $adminUserId, string $reason): Reseller
    {
        return DB::transaction(function () use ($reseller, $eligible, $adminUserId, $reason) {
            $locked = Reseller::query()->whereKey($reseller->id)->lockForUpdate()->firstOrFail();

            if ($locked->rebate_eligible === $eligible) {
                throw ValidationException::withMessages(['eligible' => [$eligible ? 'This account is already in the rebate programme.' : 'This account is already out of the rebate programme.']]);
            }

            ResellerRebateEligibilityChange::query()->create([
                'reseller_id' => $locked->id,
                'from_eligible' => $locked->rebate_eligible,
                'to_eligible' => $eligible,
                'admin_user_id' => $adminUserId,
                'reason' => $reason,
            ]);
            $locked->update(['rebate_eligible' => $eligible]);

            return $locked;
        });
    }
}
