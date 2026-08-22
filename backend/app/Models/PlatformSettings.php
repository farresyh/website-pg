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
        'maintenance_mode',
        'maintenance_message',
        'telegram_notifications_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'telegram_notifications_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['currency' => 'MYR']);
    }
}
