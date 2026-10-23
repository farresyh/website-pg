<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-126 decision 14: joki's one settings row, created by the joki
 * catalog migration. Whole ringgit throughout (decision 4).
 */
class JokiSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'payout_share_pct', 'min_stars', 'grading_price_rm', 'grading_express_price_rm',
        'grading_lands_at_stars', 'max_mythic_stars', 'roles', 'express_description', 'terms_html',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'payout_share_pct' => 'float',
        'min_stars' => 'integer',
        'grading_price_rm' => 'integer',
        'grading_express_price_rm' => 'integer',
        'grading_lands_at_stars' => 'integer',
        'max_mythic_stars' => 'integer',
        'roles' => 'array',
    ];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
