<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-074 decision 1: a `Reseller` (wallet) account's Reseller API
 * credential. `key_hash` is the only stored form of the key — see
 * `ResellerApiKeyService` (the sole writer/resolver) for the
 * generate-once-show-once contract.
 */
class ResellerApiKey extends Model
{
    protected $fillable = [
        'reseller_id',
        'name',
        'key_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
