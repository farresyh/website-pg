<?php

namespace App\Models;

use App\Services\Membership\MembershipCheckoutAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-068 decision 2 — one self-serve membership subscription payment
 * attempt: `pending` → `paid` / `failed` / `expired`. See the
 * migration's doc comment for the three-identifier design.
 */
class MembershipCheckoutAttempt extends Model
{
    protected $fillable = [
        'affiliate_id',
        'email',
        'membership_plan_id',
        'fee_sen',
        'total_charged_sen',
        'channel_code',
        'subscription_number',
        'idempotency_key',
        'payment_ref',
        'checkout_url',
        'status',
    ];

    protected $casts = [
        'fee_sen' => 'integer',
        'total_charged_sen' => 'integer',
        'status' => MembershipCheckoutAttemptStatus::class,
    ];

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
