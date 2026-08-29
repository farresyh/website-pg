<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Insert-only audit + idempotency row — one per order that actually
 * decremented a membership's quota (ADR-027 Phase 6). Never updated or
 * deleted; see `MembershipQuotaService::decrement()`.
 */
class MembershipQuotaDebit extends Model
{
    protected $fillable = [
        'order_id',
        'membership_id',
        'amount_sen',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
    ];
}
