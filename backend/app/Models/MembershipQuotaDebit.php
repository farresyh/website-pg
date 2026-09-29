<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit + idempotency row — one per order that actually decremented a
 * membership's quota (ADR-027 Phase 6). Never deleted; `restored_at`
 * (M-9, 2026-09-29 audit) is the one field `MembershipQuotaService::
 * restore()` sets, mirroring `voucher_redemptions.status`'s
 * reserved/restored marker for a failed/abandoned checkout.
 */
class MembershipQuotaDebit extends Model
{
    protected $fillable = [
        'order_id',
        'membership_id',
        'amount_sen',
        'restored_at',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
        'restored_at' => 'datetime',
    ];
}
