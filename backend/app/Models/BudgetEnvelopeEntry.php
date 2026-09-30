<?php

namespace App\Models;

use App\Services\Accounting\BudgetEnvelopeEntryCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — append-only, enforced
 * at the model layer like `SupplierLedgerEntry`: `->update()`/`->save()`
 * on an existing row and `->delete()` both throw.
 * `App\Services\Accounting\BudgetEnvelopeService` is the sole writer.
 */
class BudgetEnvelopeEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'budget_envelope_id',
        'category',
        'amount_sen',
        'transaction_date',
        'description',
        'paid_from',
        'reference_no',
        'receipt_path',
        'reverses_entry_id',
        'void_reason',
        'created_by',
    ];

    protected $casts = [
        'category' => BudgetEnvelopeEntryCategory::class,
        'amount_sen' => 'integer',
        'transaction_date' => 'date',
        'paid_from' => \App\Services\Accounting\PaidFrom::class,
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('BudgetEnvelopeEntry rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('BudgetEnvelopeEntry rows are append-only — cannot delete a persisted row.');
        });
    }

    public function budgetEnvelope(): BelongsTo
    {
        return $this->belongsTo(BudgetEnvelope::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** Null unless this entry is itself a void's reversal. */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
