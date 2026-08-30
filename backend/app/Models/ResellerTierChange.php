<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-058 58b (RES-3 + ADR-056 decision 8): one append-only row per
 * reseller wholesale-tier assignment or change. Written by
 * Admin\ResellerController when a tier is assigned/changed — never
 * updated or deleted. `from_tier_id` is null for the first assignment.
 */
class ResellerTierChange extends Model
{
    protected $fillable = [
        'reseller_id',
        'admin_user_id',
        'from_tier_id',
        'to_tier_id',
        'note',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function fromTier(): BelongsTo
    {
        return $this->belongsTo(ResellerMembershipTier::class, 'from_tier_id');
    }

    public function toTier(): BelongsTo
    {
        return $this->belongsTo(ResellerMembershipTier::class, 'to_tier_id');
    }
}
