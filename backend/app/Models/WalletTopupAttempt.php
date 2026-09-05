<?php

namespace App\Models;

use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-073 decision 3(a) / PR-G — one self-serve CHIP wallet top-up
 * attempt: `pending` -> `paid` / `failed` / `expired`. See the
 * migration's doc comment for the field shape.
 */
class WalletTopupAttempt extends Model
{
    protected $fillable = [
        'reseller_id',
        'reference',
        'amount_sen',
        'total_charged_sen',
        'channel_code',
        'status',
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
        return $this->belongsTo(Reseller::class);
    }
}
