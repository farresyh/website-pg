<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-139 decision 10: one rebate step (or the month's adjustment) for one
 * reseller and month. The amount and the calculation inputs (sales, profit,
 * ladder amount, cap, as-of) are written once, for income-tax records
 * (accountant addendum, item 6).
 */
class ResellerRebateMilestone extends Model
{
    public const KIND_STEP = 'step';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const AVAILABLE = 'available';

    public const CLAIMED = 'claimed';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'reseller_id', 'period', 'kind', 'step', 'ladder_id', 'tier_id', 'amount_sen', 'adjusted', 'state',
        'sales_sen', 'profit_sen', 'ladder_cumulative_sen', 'cap_sen', 'computed_at', 'claimed_at', 'claimed_by', 'expired_at',
    ];

    protected $casts = [
        'period' => 'date',
        'step' => 'integer',
        'amount_sen' => 'integer',
        'adjusted' => 'boolean',
        'sales_sen' => 'integer',
        'profit_sen' => 'integer',
        'ladder_cumulative_sen' => 'integer',
        'cap_sen' => 'integer',
        'computed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class)->withTrashed();
    }
}
