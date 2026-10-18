<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-124: a customer's game-account login for one order. Only this side
 * of the relation exists — `Order` never gets a `credentials()` relation
 * (decision 1, OrderCredentialTest), so no `->with()` / `toArray()` on an
 * order can pull it. `secrets` is never in toArray(); it is read only by
 * OrderCredentialController::reveal() and wiped by
 * WipeOrderCredentialsCommand.
 *
 * `fields`: non-secret values, `[{key, label, value}]`.
 * `secret_fields`: the secret fields' labels, `[{key, label}]`, kept after a wipe.
 * `secrets`: `{key: value}`, encrypted with APP_KEY (`previous_keys` rotation).
 */
class OrderCredential extends Model
{
    protected $fillable = [
        'order_id',
        'fields',
        'secret_fields',
        'secrets',
        'secrets_set_at',
        'consented_at',
        'consent_text',
    ];

    protected $hidden = ['secrets'];

    protected $casts = [
        'fields' => 'array',
        'secret_fields' => 'array',
        'secrets' => 'encrypted:array',
        'secrets_set_at' => 'datetime',
        'closed_at' => 'datetime',
        'purged_at' => 'datetime',
        'consented_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function hasSecretField(string $key): bool
    {
        return collect($this->secret_fields)->contains('key', $key);
    }

    /** What every admin who can open the order sees: never a secret value. */
    public function adminSummary(): array
    {
        return [
            'fields' => $this->fields,
            'secret_fields' => $this->secret_fields,
            'secrets_set_at' => $this->secrets_set_at?->toISOString(),
            'purged_at' => $this->purged_at?->toISOString(),
            'consented_at' => $this->consented_at?->toISOString(),
            'consent_text' => $this->consent_text,
        ];
    }
}
