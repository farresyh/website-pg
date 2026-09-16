<?php

namespace App\Models;

use App\Services\Order\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-094 decision 1 + its 2026-09-15 addendum: one row per real
 * outbound supplier call a combo `Order` makes. See the owning
 * migration's own doc comment for `leg_number`'s role in the per-leg
 * idempotency key and the ledger dedup fix (decision 18).
 *
 * ADR-103 decisions 1-2: `reference_number` is this leg's own,
 * independently regenerable idempotency key (formerly derived on the
 * fly as `{order.reference_number}-L{leg_number}` and never stored).
 * `resend_unsafe_with_same_reference` is the leg-level twin of
 * ADR-102 decision 5's `SupplierResponse` flag — set only when this
 * leg lands on NeedsReview.
 */
class OrderDeliveryLeg extends Model
{
    protected $fillable = [
        'order_id',
        'component_package_id',
        'supplier_id',
        'leg_number',
        'reference_number',
        'status',
        'supplier_reference',
        'delivered_at',
        'failure_reason',
        'resend_unsafe_with_same_reference',
    ];

    protected $casts = [
        'leg_number' => 'integer',
        'status' => DeliveryStatus::class,
        'delivered_at' => 'datetime',
        'resend_unsafe_with_same_reference' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function componentPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'component_package_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
