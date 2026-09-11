<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 decision 2: one capital transfer of company funds into a
 * supplier's prepaid account. Not append-only itself (unlike
 * `SupplierLedgerEntry`) — the linked `TOPUP` ledger entry snapshots its
 * own `amount`/`currency` at creation time, so a later correction here
 * (e.g. fixing a typo'd reference number) can't retroactively corrupt the
 * ledger. `SupplierFundingService` is the intended sole writer.
 */
class SupplierTransfer extends Model
{
    protected $fillable = [
        'supplier_id',
        'source_channel',
        'amount_myr_sent',
        'fee_myr',
        'currency',
        'amount_foreign_received',
        'effective_rate',
        'receipt_path',
        'reference_no',
        'created_by',
    ];

    protected $casts = [
        'amount_myr_sent' => 'integer',
        'fee_myr' => 'integer',
        'amount_foreign_received' => 'decimal:4',
        'effective_rate' => 'decimal:8',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
