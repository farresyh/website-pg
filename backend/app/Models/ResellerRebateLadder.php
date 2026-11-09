<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-139 decisions 3, 6: one ladder version, effective from a 1st (KL).
 * `steps` = [{threshold_sen, rewards: {tier_id: cumulative_sen}}], ascending;
 * `max_share_bp` is the entitlement cap in basis points (≤ the 50% ceiling).
 * Written once by RebateLadderService, never edited: a change is a new row.
 */
class ResellerRebateLadder extends Model
{
    protected $fillable = ['effective_from', 'max_share_bp', 'steps', 'admin_user_id'];

    protected $casts = [
        'effective_from' => 'date',
        'max_share_bp' => 'integer',
        'steps' => 'array',
    ];

    /** Cumulative reward (sen) at 1-based step $step for a tier; null if the tier has no column (decision 44). */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function cumulativeFor(int $tierId, int $step): ?int
    {
        if ($step === 0) {
            return 0;
        }

        $reward = $this->steps[$step - 1]['rewards'][(string) $tierId] ?? null;

        return $reward === null ? null : (int) $reward;
    }

    /** Highest 1-based step whose threshold the sales reach, 0 if none. */
    public function reachedStep(int $salesSen): int
    {
        $reached = 0;
        foreach ($this->steps as $i => $s) {
            if ($salesSen >= (int) $s['threshold_sen']) {
                $reached = $i + 1;
            }
        }

        return $reached;
    }
}
