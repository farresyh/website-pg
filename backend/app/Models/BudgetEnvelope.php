<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — a director-controlled
 * discretionary budget bucket (Capital Rolling, Marketing Budget, etc.).
 * No stored balance column — always derived as `SUM(entries.amount_sen)`,
 * same "never a mutable balance column" discipline `ledger_entries`
 * (ADR-002) and `supplier_ledger_entries` (ADR-083) both already follow.
 */
class BudgetEnvelope extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(BudgetEnvelopeEntry::class);
    }

    /** SUM(amount_sen) across every entry — a void's reversal entry always nets itself out automatically, no "exclude voided" filter needed. */
    public function balanceSen(): int
    {
        return (int) $this->entries()->sum('amount_sen');
    }
}
