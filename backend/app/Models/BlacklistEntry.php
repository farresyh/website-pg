<?php

namespace App\Models;

use App\Services\Fraud\BlacklistEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-007 / FRAUD-1..3. `is_active=false` (not a hard delete) is how
 * FRAUD-3's "remove" is implemented - see the migration's own doc
 * comment for why (blacklist_hits history must stay traceable).
 */
class BlacklistEntry extends Model
{
    protected $fillable = [
        'type',
        'value',
        'reason',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'type' => BlacklistEntryType::class,
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(BlacklistHit::class);
    }
}
