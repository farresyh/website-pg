<?php

namespace App\Models;

use App\Services\Accounting\SupplierLedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'supplier_fee',
        'effective_rate',
        'receipt_path',
        'reference_no',
        'voided_at',
        'void_reason',
        'created_by',
    ];

    protected $casts = [
        'amount_myr_sent' => 'integer',
        'fee_myr' => 'integer',
        'amount_foreign_received' => 'decimal:4',
        'supplier_fee' => 'decimal:4',
        'effective_rate' => 'decimal:8',
        'voided_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /**
     * ADR-083 2026-09-15 addendum: every `MANUAL_ADJUSTMENT` entry that
     * corrects this transfer — `reference_type`/`reference_id` is the
     * same loosely-typed pair every other ledger table uses (not a real
     * polymorphic relation), so this is a plain `hasMany` with the type
     * pinned as an extra constraint rather than Eloquent's own
     * `morphMany`.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class, 'reference_id')
            ->where('reference_type', 'supplier_transfer')
            // Excludes this transfer's own TOPUP row (same reference
            // pair, but that's the original entry, not a correction of it).
            ->where('type', SupplierLedgerEntryType::ManualAdjustment->value)
            ->orderByDesc('created_at');
    }

    /**
     * ADR-083 2026-09-15 addendum: the real wallet credit —
     * `amount_foreign_received` (gross, the receipt-literal figure)
     * less the supplier's own deposit-side fee. This is what
     * `SupplierFundingService::recordTransfer()` actually writes as
     * the TOPUP ledger amount; kept here too so a caller (the void
     * flow, a future report) never has to redo the subtraction
     * inline.
     */
    public function netForeignReceived(): string
    {
        $gross = (float) $this->amount_foreign_received;
        $fee = (float) ($this->supplier_fee ?? 0);

        return number_format($gross - $fee, 4, '.', '');
    }
}
