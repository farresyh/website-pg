<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-017 decision #4: one row per admin resend attempt, mirroring
 * PriceChangeLog/DeactivationLog's own audit-trail discipline — this
 * is what the Admin Orders detail page renders as "Delivery Logs"
 * history, since `Order.supplier_response` alone is overwritten on
 * every attempt and can't show anything before the latest one.
 */
class OrderResendAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'package_id',
        'cost_price_sen',
        'reseller_cost_price_sen',
        'price_diff_sen',
        'outcome',
        'supplier_response',
        'note',
        'triggered_by',
    ];

    protected $casts = [
        'cost_price_sen' => 'integer',
        'reseller_cost_price_sen' => 'integer',
        'price_diff_sen' => 'integer',
        'supplier_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
