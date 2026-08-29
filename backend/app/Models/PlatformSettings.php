<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 decision 4: platform-wide ops config, one singleton row.
 * `current()` mirrors Reseller::platformOwner()'s firstOrCreate
 * shape — a safety net for any environment that skipped seeding.
 */
class PlatformSettings extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'currency',
        'vip_spend_threshold_sen',
        'maintenance_mode',
        'maintenance_message',
        'telegram_notifications_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
        'membership_enabled',
    ];

    protected $casts = [
        'vip_spend_threshold_sen' => 'integer',
        'maintenance_mode' => 'boolean',
        'telegram_notifications_enabled' => 'boolean',
        'membership_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        // All three defaults are given explicitly, not left to the column's
        // DB DEFAULT — Eloquent never re-fetches a server-applied default
        // after INSERT, so a freshly-created row would read back null in
        // memory (ADR-049's VIP threshold needs an int, not null; ADR-027's
        // membership_enabled needs a real false, not null, per its own
        // 2026-08-29 addendum decision 20).
        return static::query()->firstOrCreate([], [
            'currency' => 'MYR',
            'vip_spend_threshold_sen' => 500000,
            'membership_enabled' => false,
        ]);
    }
}
