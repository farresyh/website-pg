<?php

namespace App\Models;

use App\Services\Affiliate\AffiliateSubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-056 (grilled 2026-08-30) decisions 3 & 6: one row per affiliate
 * tracking which wholesale tier they're subscribed to and where they are
 * in the billing/grace/lapse cycle. Driven by `ChargeAffiliateTierFeesCommand`
 * and `AffiliateTierFeeService`.
 */
class AffiliateSubscription extends Model
{
    protected $fillable = [
        'affiliate_id',
        'affiliate_membership_tier_id',
        'status',
        'current_period_started_at',
        'next_charge_at',
        'grace_until',
    ];

    protected $casts = [
        'status' => AffiliateSubscriptionStatus::class,
        'current_period_started_at' => 'datetime',
        'next_charge_at' => 'datetime',
        'grace_until' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(AffiliateMembershipTier::class, 'affiliate_membership_tier_id');
    }

    /**
     * Whether this subscription currently entitles the affiliate to their
     * tier's cost-anchored wholesale base price. `active` and `grace` both
     * do; `lapsed` does not.
     */
    public function grantsWholesaleRate(): bool
    {
        return $this->status->grantsWholesaleRate();
    }
}
