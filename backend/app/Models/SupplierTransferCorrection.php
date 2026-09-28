<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 2026-09-28 addendum: a metadata-only correction to a
 * `SupplierTransfer` row — append-only, model-enforced (same pattern as
 * `SupplierLedgerEntry`), never touches the FX ledger. `changes` is a
 * JSON diff of every field changed in one edit action.
 */
class SupplierTransferCorrection extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'supplier_transfer_id',
        'changes',
        'reason',
        'admin_user_id',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('SupplierTransferCorrection rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('SupplierTransferCorrection rows are append-only — cannot delete a persisted row.');
        });
    }

    public function supplierTransfer(): BelongsTo
    {
        return $this->belongsTo(SupplierTransfer::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
