<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5: an insert-only
 * audit row per membership-fee payment received. See the migration's own
 * doc comment for why `idempotency_key` carries a unique index — this
 * is the double-submit serialization point, not just a bookkeeping tag.
 */
class MembershipFeeRecord extends Model
{
    protected $fillable = [
        'membership_id',
        'membership_plan_id',
        'amount_sen',
        'admin_user_id',
        'reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }
}
