<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-056 (grilled 2026-08-30) decision 1: a paid, admin-CRUD wholesale
 * -rate tier an affiliate subscribes to monthly. Fully independent of the
 * consumer `MembershipPlan` (ADR-027) — no shared table, no buyer-type
 * discriminator. `markup_percent` is applied over supplier `cost_price`
 * (see `PricingService::calculateForAffiliate()`).
 */
class AffiliateMembershipTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'monthly_fee_sen',
        'markup_percent',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'monthly_fee_sen' => 'integer',
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AffiliateSubscription::class);
    }
}
