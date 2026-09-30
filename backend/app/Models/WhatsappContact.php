<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-116 decision 5: one phone number's WhatsApp opt-in state. Written only
 * by CustomerWhatsAppInboundService.
 */
class WhatsappContact extends Model
{
    protected $fillable = [
        'phone',
        'opted_in_at',
        'opt_in_source',
        'opted_out_at',
    ];

    protected $casts = [
        'opted_in_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    /** Opted in and not opted out: may receive Delivered receipts. */
    public static function receivesReceipts(string $phone): bool
    {
        return static::query()->where('phone', $phone)->whereNull('opted_out_at')->exists();
    }
}
