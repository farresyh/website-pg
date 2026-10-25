<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-126 decision 16: one row per Assign, written in the same transaction. Never edited. */
class JokiAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_supplier_id', 'to_supplier_id', 'assigned_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fromSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'from_supplier_id');
    }

    public function toSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'to_supplier_id');
    }
}
