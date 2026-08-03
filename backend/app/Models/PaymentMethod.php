<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SET-7/SET-11: an admin-curated, per-channel record of which Xendit
 * payment channels are actually usable and what they cost — see the
 * create_payment_methods_table migration's doc comment for why this
 * is curated rather than synced (no Xendit API exists to discover
 * per-account channel activation).
 */
class PaymentMethod extends Model
{
    protected $fillable = [
        'channel_code',
        'method_key',
        'label',
        'category',
        'gateway',
        'is_active',
        'percentage_rate',
        'flat_fee_sen',
        'requires_issuer',
        'last_tested_at',
        'last_test_result',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'percentage_rate' => 'decimal:2',
        'flat_fee_sen' => 'integer',
        'requires_issuer' => 'boolean',
        'last_tested_at' => 'datetime',
    ];
}
