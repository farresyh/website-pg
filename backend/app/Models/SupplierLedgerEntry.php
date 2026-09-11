<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 decision 2: the supplier funding ledger — append-only, in the
 * supplier's own currency (never MYR sen). Unlike `LedgerEntry` (append-
 * only by discipline only, ADR-002), this one enforces it at the model
 * layer: `->update()`/`->save()` on an existing row and `->delete()` both
 * throw. `App\Services\Accounting\SupplierFundingService` is the intended
 * sole writer.
 */
class SupplierLedgerEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'supplier_id',
        'type',
        'amount',
        'currency',
        'reference_type',
        'reference_id',
        'created_by',
        'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('SupplierLedgerEntry rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('SupplierLedgerEntry rows are append-only — cannot delete a persisted row.');
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
