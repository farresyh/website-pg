<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-123 decision 8: one answered check on the public Game ID Checker.
 * Usage and abuse visibility only; never read to approve a checkout.
 */
class PublicPlayerCheck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'player_validator_profile_id',
        'player_id',
        'server_id',
        'status',
        'nickname',
        'matched_game_id',
        'cache_hit',
    ];

    protected $casts = [
        'cache_hit' => 'boolean',
    ];
}
