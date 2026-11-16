<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-058 2026-11-16 addendum: one change of an affiliate's payout bank
 * details. Append-only; written only by AffiliateBankDetailsService.
 */
class AffiliateBankChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'affiliate_id',
        'affiliate_user_id',
        'impersonation_session_id',
        'old_bank_name',
        'old_bank_account_no',
        'old_bank_account_holder',
        'new_bank_name',
        'new_bank_account_no',
        'new_bank_account_holder',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
