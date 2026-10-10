<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, append-only (ADR-002/D2) — never call ->update() or ->delete()
 * on this model from application code. Only ever created.
 */
class LedgerEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'created_by',
        'reason',
        'idempotency_key',
    ];

    /** Both are DB-level double-write backstops (2026-09-29 audit), not data anyone reads. */
    protected $hidden = [
        'dedupe_key',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    /** ADR-083 2026-10-10 addendum, decision 16 — enforced, not just a convention. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('LedgerEntry rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('LedgerEntry rows are append-only — cannot delete a persisted row.');
        });
    }
}
