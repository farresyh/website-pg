<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pure lock/mutex anchor — deliberately has no balance column.
 * See docs/adr.md ADR-002 addendum on the two-table ledger locking design.
 */
class LedgerAccount extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
    ];
}
