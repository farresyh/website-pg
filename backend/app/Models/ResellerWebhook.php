<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-084 PR-3 decision 4: a `Reseller` (wallet) account's single
 * delivery-webhook endpoint. `ResellerWebhookService` is the sole
 * writer/rotator — see it for the generate-once-show-once contract.
 *
 * `secret` is `encrypted` (not hashed): every outbound delivery signs
 * the body with `hmac-sha256(secret, rawBody)`, so the plaintext must be
 * reproducible. Same trust model as `Supplier.api_config` and
 * `AdminUser.mfa_secret`. Always `$hidden` — it must never reach an API
 * response body.
 */
class ResellerWebhook extends Model
{
    protected $fillable = [
        'reseller_id',
        'url',
        'secret',
        'is_active',
    ];

    protected $hidden = [
        'secret',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
