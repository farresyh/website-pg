<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-067 2026-11-07 addendum: the one record of which Game a supplier's
 * group belongs to. It outlives the items, which sync creates and prunes.
 */
class SupplierGroupLink extends Model
{
    protected $fillable = [
        'supplier_id',
        'group_label',
        'game_id',
    ];

    public static function for(int $supplierId, string $groupLabel): ?self
    {
        return static::query()->where('supplier_id', $supplierId)->where('group_label', $groupLabel)->first();
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
