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
 *
 * ADR-061 decision 5 (PR-B): identity is now per-brand — `affiliate_id`
 * plus `unique(affiliate_id, email)`. The `BelongsToAffiliate` scoping
 * trait is deliberately NOT applied: every read path is either a guest
 * storefront endpoint (no `affiliate` guard active, so the scope would
 * be a no-op) or the admin registry (cross-brand on purpose).
 * Isolation is enforced by explicit `where('affiliate_id', ...)` in
 * `OtpService`, `CheckoutController`, `CatalogController`,
 * `MembershipController`, and `MembershipFeeService` — same reasoning
 * as `AffiliateUser` (ADR-058 58b). Revisit if ADR-059 adds a
 * affiliate-guard "my members" screen.
 */
class Membership extends Model
{
    protected $fillable = [
        'affiliate_id',
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

    /** ADR-061 decision 5: the brand this membership belongs to. */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
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
