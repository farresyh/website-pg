<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category',
        'is_active',
        'sort_order',
        'image_url',
        'banner_url',
        'supplier_mappings',
        'validation_rules',
        'player_validator_profile_id',
        'player_validator_enabled',
        'seo_title',
        'seo_title_local',
        'seo_description',
        'seo_description_local',
        'seo_keywords',
        'seo_og_image',
        'schema_brand',
        'schema_category',
        'no_index',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'supplier_mappings' => 'array',
        'validation_rules' => 'array',
        'player_validator_enabled' => 'boolean',
        'no_index' => 'boolean',
    ];

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function playerRegionMappings(): HasMany
    {
        return $this->hasMany(PlayerRegionMapping::class);
    }

    public function playerValidatorProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerValidatorProfile::class);
    }
}
