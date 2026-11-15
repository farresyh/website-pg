<?php

namespace App\Models;

use App\Services\Supplier\SupplierOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-102 2026-11-15 addendum: a supplier result that arrived after the order
 * or combo leg was final. Append-only, written only by
 * OrderFulfillmentService. A Success may mean the player has the item, so it
 * blocks automatic and cash refunds (`Order::scopeWithLateSupplierResult`);
 * a Failure is recorded and alerted but blocks nothing.
 */
class LateSupplierResult extends Model
{
    protected $fillable = [
        'order_id',
        'order_delivery_leg_id',
        'outcome',
        'supplier_reference',
        'payload',
    ];

    protected $casts = [
        'outcome' => SupplierOutcome::class,
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function leg(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryLeg::class, 'order_delivery_leg_id');
    }
}
