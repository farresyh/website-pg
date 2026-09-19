<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — one row per uploaded CHIP
 * settlement `.xlsx`.
 */
class PaymentSettlement extends Model
{
    protected $fillable = [
        'date_from',
        'date_to',
        'expected_gross_sen',
        'expected_fee_sen',
        'expected_net_sen',
        'file_gross_sen',
        'file_fee_sen',
        'file_net_sen',
        'actual_bank_amount_sen',
        'status',
        'variance_note',
        'original_filename',
        'admin_user_id',
    ];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'expected_gross_sen' => 'integer',
        'expected_fee_sen' => 'integer',
        'expected_net_sen' => 'integer',
        'file_gross_sen' => 'integer',
        'file_fee_sen' => 'integer',
        'file_net_sen' => 'integer',
        'actual_bank_amount_sen' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(ChipSettledTransaction::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
