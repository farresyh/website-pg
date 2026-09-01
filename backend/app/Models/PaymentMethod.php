<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SET-7/SET-11: an admin-curated, per-channel record of which CHIP
 * payment channels are actually usable and what they cost — see the
 * create_payment_methods_table migration's doc comment for why this is
 * curated rather than synced. CHIP does expose
 * `GET /payment_methods/?brand_id=…&currency=MYR` (ADR-022's 2026-09-01
 * addendum), but auto-syncing from it is deliberately not built —
 * activation stays a manual admin action gated on a real smoke test
 * (ADR-022 decision 5).
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
