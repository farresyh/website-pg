<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'member_pricing_enabled',
        'credential_max_days',
        'is_joki',
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
        'credential_max_days' => 'integer',
        'is_joki' => 'boolean',
        'sort_order' => 'integer',
        'important_notes' => 'array',
        'supplier_mappings' => 'array',
        'validation_rules' => 'array',
        'player_validator_enabled' => 'boolean',
        'member_pricing_enabled' => 'boolean',
        'no_index' => 'boolean',
    ];

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

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
     * ADR-097 2026-10-05 addendum, decision 29 — `numeric` unless an
     * admin opted the game into `text` (e.g. a Riot ID).
     *
     * @return 'numeric'|'text'
     */
    public function playerIdFormat(): string
    {
        return ($this->validation_rules['player_id_format'] ?? null) === 'text' ? 'text' : 'numeric';
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
    /**
     * ADR-125 decision 6: a login game's per-game form,
     * `[{key, label, type, options?, required, secret, is_identifier}]`,
     * written only by GameCredentialSchemaController (GameFulfilmentRule).
     * Empty for a UID game.
     *
     * @return list<array<string, mixed>>
     */
    public function credentialFields(): array
    {
        $fields = $this->validation_rules['credential_fields'] ?? null;

        return is_array($fields) ? array_values($fields) : [];
    }

    /** ADR-126 decision 2: a joki game, sold only through `/joki`, never listed in a catalog. */
    public function scopeJoki(Builder $query): Builder
    {
        return $query->where('is_joki', true);
    }

    public function scopeNotJoki(Builder $query): Builder
    {
        return $query->where('is_joki', false);
    }

    /** ADR-125 decision 5: derived from the schema, never a second flag. */
    public function isLoginGame(): bool
    {
        return $this->credentialFields() !== [];
    }

    /**
     * The one non-secret field that fills `player_id` (decision 6).
     *
     * @return array<string, mixed>|null
     */
    public function identifierField(): ?array
    {
        foreach ($this->credentialFields() as $field) {
            if (! empty($field['is_identifier'])) {
                return $field;
            }
        }

        return null;
    }

    /**
     * ADR-125 decisions 7/35/37: the one gate for a member price on this
     * game. Read where the membership id is resolved (CheckoutController),
     * the public package price and the savings-badge sample. A row
     * inserted without the column (seeder) reads as on, the DB default.
     */
    public function memberPricingApplies(): bool
    {
        return $this->member_pricing_enabled ?? true;
    }

    public function effectiveDeliverySubtext(): string
    {
        return $this->delivery_subtext ?? 'Average delivery: 1–3 minutes';
    }
}
