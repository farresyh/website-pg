<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-125 decisions 50-51: one short login link sent to a supplier group.
 * Only the token's hash is stored. Minted and checked by
 * SupplierLoginLinkService; an expired row is pruned daily.
 */
class SupplierLoginLink extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'token_hash',
        'order_id',
        'supplier_id',
        'reference_number',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function prunable()
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
