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
        'reseller_code',
        'category',
        'is_active',
        'sort_order',
        'image_url',
        'banner_url',
        'description',
        'important_notes',
        'delivery_mode',
        'delivery_subtext',
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
        'important_notes' => 'array',
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

    /**
     * ADR-097 decision 15 — single source of truth for this array-key
     * read, called identically at every SupplierOrderRequest/
     * SupplierStatusCheckRequest call site instead of each one
     * repeating the raw `validation_rules['customer_no_separator']`
     * literal (typo-drift risk across the 4 real call sites).
     * Digiflazz-specific; meaningless for any other supplier, but this
     * accessor doesn't know or care who the game's current supplier
     * is — the adapter that never reads it (Gamevion) just ignores it.
     */
    public function customerNoSeparatorOverride(): ?string
    {
        return $this->validation_rules['customer_no_separator'] ?? null;
    }

    /**
     * ADR-097 decision 6/7 — the admin-curated Zone ID picklist. Empty
     * array normalizes to null (same "unset preserves free text"
     * behavior either way, one less shape for a caller to check).
     *
     * @return list<string>|null
     */
    public function zoneOptions(): ?array
    {
        $options = $this->validation_rules['zone_options'] ?? null;

        return is_array($options) && $options !== [] ? $options : null;
    }

    /**
     * ADR-109 decision 1 — `delivery_subtext` stays nullable at the DB
     * layer, but every reader (CatalogController, admin edit form)
     * sees the same default text ProductHeaderCard hardcoded before
     * this column existed, so no admin action is required for any
     * existing game to keep behaving exactly as it does today.
     */
    public function effectiveDeliverySubtext(): string
    {
        return $this->delivery_subtext ?? 'Average delivery: 1–3 minutes';
    }
}
