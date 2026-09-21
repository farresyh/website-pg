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
 *
 * ADR-106 decision 2: widened beyond admin resends — `attempt_type`
 * (`initial`/`resend`/`retry`/`manual_confirm`) now discriminates which
 * of OrderFulfillmentService::fulfill()/markDeliveredManually() or
 * OrderResendService::resend() wrote a given row. Same table, no
 * rename, no second model — a query like
 * `resolvePendingResendAttempt()`'s stays entirely type-agnostic.
 *
 * ADR-106 addendum (2026-09-21): `order_delivery_leg_id` extends this
 * same table to a combo leg's own per-attempt history — null for an
 * order-level row, set for a leg-scoped one. `package_id` for a
 * leg-scoped row is the leg's own component package, not the order's.
 */
class OrderResendAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'order_delivery_leg_id',
        'attempt_type',
        'package_id',
        'cost_price_sen',
        'standard_selling_price_sen',
        'price_diff_sen',
        'outcome',
        'supplier_response',
        'note',
        'triggered_by',
    ];

    protected $casts = [
        'cost_price_sen' => 'integer',
        'standard_selling_price_sen' => 'integer',
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

    public function orderDeliveryLeg(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryLeg::class);
    }
}
