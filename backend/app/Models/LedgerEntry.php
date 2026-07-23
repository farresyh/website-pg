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
    ];

    protected $casts = [
        'amount' => 'integer',
    ];
}
