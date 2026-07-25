<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerValidation extends Model
{
    protected $fillable = [
        'game_id',
        'player_id',
        'server_id',
        'status',
        'country_code',
        'nickname',
        'provider',
        'validated_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
