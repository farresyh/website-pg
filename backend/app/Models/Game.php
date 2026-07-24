<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'seo_title',
        'seo_title_local',
        'seo_description',
        'seo_description_local',
        'seo_keywords',
        'seo_og_image',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'supplier_mappings' => 'array',
        'validation_rules' => 'array',
    ];

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
