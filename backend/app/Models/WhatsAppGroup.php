<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-125 decision 11: one bot-session WhatsApp group, owned by a reseller
 * (ADR-075) or a manual supplier. Linking and every ownership rule live in
 * WhatsAppGroupService (decisions 55-56).
 */
class WhatsAppGroup extends Model
{
    public const OWNER_RESELLER = 'reseller';

    public const OWNER_SUPPLIER = 'supplier';

    // Eloquent would pluralise "WhatsApp" to whats_app_groups.
    protected $table = 'whatsapp_groups';

    protected $fillable = [
        'whatsapp_group_id',
        'owner_type',
        'owner_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'owner_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'owner_id');
    }

    public function isOwnedBy(string $ownerType, int $ownerId): bool
    {
        return $this->owner_type === $ownerType && $this->owner_id === $ownerId;
    }
}
