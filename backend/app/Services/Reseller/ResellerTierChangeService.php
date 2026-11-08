<?php

namespace App\Services\Reseller;

use App\Models\Reseller;
use App\Models\ResellerWalletTierChange;
use App\Support\MalaysiaTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-139 decision 7: the one writer of a reseller's wallet tier.
 *
 * - A reseller's first tier is effective at once (provisioning, or a legacy
 *   reseller with none).
 * - A change on a reseller that already has a tier waits for the next 1st
 *   (KL), so a month is priced and rewarded on one tier. One change waits at
 *   a time: a new choice replaces it, choosing the current tier cancels it.
 * - `applyDue()` (hourly task) applies every change whose 1st has come.
 *
 * Pricing keeps reading `resellers.reseller_tier_id`; this table is the
 * history the rebate reads a month's tier from.
 */
final class ResellerTierChangeService
{
    public function recordInitial(Reseller $reseller, ?int $adminUserId = null): void
    {
        ResellerWalletTierChange::query()->create([
            'reseller_id' => $reseller->id,
            'from_tier_id' => null,
            'to_tier_id' => $reseller->reseller_tier_id,
            'effective_on' => now(MalaysiaTime::TIMEZONE)->toDateString(),
            'applied_at' => now(),
            'admin_user_id' => $adminUserId,
        ]);
    }

    public function change(Reseller $reseller, int $toTierId, ?int $adminUserId): void
    {
        DB::transaction(function () use ($reseller, $toTierId, $adminUserId) {
            $locked = Reseller::query()->lockForUpdate()->findOrFail($reseller->id);
            $this->pending($locked)->delete();

            if ($locked->reseller_tier_id === null) {
                $locked->update(['reseller_tier_id' => $toTierId]);
                $this->recordInitial($locked, $adminUserId);

                return;
            }

            if ($locked->reseller_tier_id === $toTierId) {
                return;
            }

            ResellerWalletTierChange::query()->create([
                'reseller_id' => $locked->id,
                'from_tier_id' => $locked->reseller_tier_id,
                'to_tier_id' => $toTierId,
                'effective_on' => now(MalaysiaTime::TIMEZONE)->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'admin_user_id' => $adminUserId,
            ]);
        });
    }

    /** @return int how many changes were applied */
    public function applyDue(): int
    {
        $due = ResellerWalletTierChange::query()
            ->whereNull('applied_at')
            ->whereDate('effective_on', '<=', now(MalaysiaTime::TIMEZONE)->toDateString())
            ->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id) {
                $change = ResellerWalletTierChange::query()->lockForUpdate()->find($id);
                if ($change === null || $change->applied_at !== null) {
                    return;
                }

                $reseller = Reseller::withTrashed()->lockForUpdate()->findOrFail($change->reseller_id);
                $reseller->update(['reseller_tier_id' => $change->to_tier_id]);
                $change->update(['applied_at' => now()]);
            });
        }

        return $due->count();
    }

    /** @return Builder<ResellerWalletTierChange> */
    private function pending(Reseller $reseller): Builder
    {
        return ResellerWalletTierChange::query()->where('reseller_id', $reseller->id)->whereNull('applied_at');
    }
}
