<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-083 2026-10-10 addendum, decision 14 — where company cash physically
 * sits, by name only (no account numbers). Its balance is never stored
 * here: each month close snapshots it.
 */
class CashAccount extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
