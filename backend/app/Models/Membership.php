<?php

namespace App\Models;

use App\Services\Membership\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-027 decision 2/3, narrowed by its 2026-08-29 addendum decision
 * 27: identity is email-keyed (OTP delivery moved to Plunk email, off
 * WhatsApp/phone) — one row per email, ever. `cycle_started_at` is the
 * rolling-30-day quota anchor (decision 7); `expires_at` is the
 * fee-paid-through date. Order-history linkage (decision 3) matches
 * `Order.customer_email` against this row's `email`, unchanged from
 * the base ADR's design other than which column it matches on.
 */
class Membership extends Model
{
    protected $fillable = [
        'email',
        'membership_plan_id',
        'status',
        'cycle_started_at',
        'quota_remaining_sen',
        'expires_at',
    ];

    protected $casts = [
        'status' => MembershipStatus::class,
        'cycle_started_at' => 'datetime',
        'quota_remaining_sen' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    /**
     * Orders priced as this membership (orders.membership_id, stamped at
     * checkout in ADR-027 Phase 6). Registry "orders count" only — full
     * order history by email remains decision 3's customer_email match.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'membership_id');
    }

    public function feeRecords(): HasMany
    {
        return $this->hasMany(MembershipFeeRecord::class);
    }
}
