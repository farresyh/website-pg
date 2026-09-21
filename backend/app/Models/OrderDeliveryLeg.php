<?php

namespace App\Models;

use App\Services\Order\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 *
 * ADR-107 decision 1: `selling_price_sen` freezes this leg's own
 * component-package `standard_selling_price` at `seedDeliveryLegs()`
 * time (checkout) — feeds decision 4's partial-combo voucher
 * apportionment. No `cost_price_sen` twin: decision 2's platformProfit
 * reconciliation reads `componentPackage->cost_price` LIVE at final
 * resolution instead (see the owning migration's doc comment for why).
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
        'selling_price_sen',
    ];

    protected $casts = [
        'leg_number' => 'integer',
        'status' => DeliveryStatus::class,
        'delivered_at' => 'datetime',
        'resend_unsafe_with_same_reference' => 'boolean',
        'selling_price_sen' => 'integer',
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

    /** ADR-106 addendum (2026-09-21) — this leg's own durable per-attempt history. */
    public function attempts(): HasMany
    {
        return $this->hasMany(OrderResendAttempt::class);
    }
}
