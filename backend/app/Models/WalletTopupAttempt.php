<?php

namespace App\Models;

use App\Models\Concerns\LeavesPendingOnce;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ADR-073 decision 3(a) / PR-G — one self-serve CHIP wallet top-up
 * attempt: `pending` -> `paid` / `failed` / `expired`. See the
 * migration's doc comment for the field shape.
 */
class WalletTopupAttempt extends Model
{
    use LeavesPendingOnce;

    protected $fillable = [
        'reseller_id',
        'reference',
        'amount_sen',
        'total_charged_sen',
        'channel_code',
        'status',
        'gateway_fee_sen',
        'chip_payment_ref',
        'checkout_url',
        'expires_at',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
        'total_charged_sen' => 'integer',
        'status' => WalletTopupAttemptStatus::class,
        'expires_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        // ADR-131 decision 17: a closed reseller is soft-deleted and its history keeps its name.
        return $this->belongsTo(Reseller::class)->withTrashed();
    }

    /** The wallet credit a paid top-up wrote, once: its accounting date (ADR-127 F5). */
    public function ledgerCredit(): HasOne
    {
        return $this->hasOne(LedgerEntry::class, 'reference_id')
            ->where('reference_type', 'wallet_topup_attempt')
            ->where('type', 'wallet_topup');
    }
}
