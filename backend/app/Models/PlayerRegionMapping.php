<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRegionMapping extends Model
{
    protected $fillable = [
        'player_validator_profile_id',
        'country_code',
        'country_name',
        'game_id',
    ];

    public function validatorProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerValidatorProfile::class, 'player_validator_profile_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
