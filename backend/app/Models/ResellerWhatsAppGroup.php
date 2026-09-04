<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-075 decision 2 / PR-F build addendum: the Reseller Bot channel's
 * account identifier — a WhatsApp group mapped to a `Reseller` (wallet).
 * `is_active = false` is the unlink/deactivate state (soft, never
 * deleted — keeps the historical link auditable).
 */
class ResellerWhatsAppGroup extends Model
{
    // Eloquent's default snake_case pluralization splits "WhatsApp" into
    // "whats_app" (an uppercase letter preceded by a lowercase one always
    // gets an underscore) — reseller_whats_app_groups, not the migration's
    // actual reseller_whatsapp_groups. Explicit to avoid that surprise.
    protected $table = 'reseller_whatsapp_groups';

    protected $fillable = [
        'reseller_id',
        'whatsapp_group_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
