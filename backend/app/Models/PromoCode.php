<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-118 V3/V11/V17: a public, budget-capped KOL code. `code` is stored
 * uppercase and never reused. The budget used is computed from its
 * redemptions (CampaignService::usedSen), never kept as a counter.
 */
class PromoCode extends Model
{
    protected $fillable = [
        'campaign_id',
        'code',
        'discount_percent',
        'max_discount_sen',
        'budget_sen',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'max_discount_sen' => 'integer',
        'budget_sen' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /** V17: trimmed and uppercased; how both storage and lookup compare codes. */
    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function isRedeemableNow(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()))
            && ! $this->campaign->hasEnded();
    }
}
