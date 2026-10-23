<?php

namespace App\Services\Catalog;

use App\Models\Game;
use App\Models\Supplier;
use Illuminate\Validation\Validator;

/**
 * ADR-125 decisions 5/33/46: a game is a login game (credential schema,
 * manual packages) or a UID game (supplier packages), never both. Every
 * write path that could mix the two asks this one rule:
 * GameCredentialSchemaController (create + schema write), manual package
 * create, PromoteSupplierProductRequest, LinkSupplierProductCategoryRequest,
 * UpdateGameRequest and StoreComboPackageRequest. Each method returns the
 * error message, or null when allowed.
 */
class GameFulfilmentRule
{
    public const FIELD_TYPES = ['text', 'email', 'password', 'select'];

    public const MAX_FIELDS = 8;

    /** A supplier-product package (promote, category link) needs a UID game. */
    public static function supplierPackage(Game $game): ?string
    {
        return $game->isLoginGame()
            ? 'This is a login game: its packages are manual, not supplier products.'
            : null;
    }

    /** A manual package needs a login game and a manual supplier. */
    public static function manualPackage(Game $game, Supplier $supplier): ?string
    {
        if (! $game->isLoginGame()) {
            return 'Manual packages need a login game (one with a credential schema).';
        }

        if ($supplier->isApiBacked()) {
            return 'A manual package needs a manual supplier.';
        }

        // ADR-126: joki's packages are kept by joki, on the pool.
        return $supplier->joki_role !== null
            ? 'A penjoki or the joki pool cannot supply an ordinary package.'
            : null;
    }

    /** Decision 5/Q6: no combo of a manual supplier's packages. */
    public static function comboComponentSupplier(?Supplier $supplier): ?string
    {
        return $supplier !== null && ! $supplier->isApiBacked()
            ? 'A combo cannot contain a manual supplier\'s package.'
            : null;
    }

    /**
     * Decision 46: turning the schema on or off is refused while the game
     * has any package (inactive ones too), so the two kinds never mix.
     */
    public static function schemaSwitch(Game $game, bool $becomesLoginGame): ?string
    {
        if ($game->exists && $game->isLoginGame() !== $becomesLoginGame && $game->packages()->exists()) {
            return 'Remove this game\'s packages before switching it between a login game and a Player ID game.';
        }

        return null;
    }

    /**
     * Decisions 5/33/46: what a login game may not have, checked against
     * the request's values falling back to the stored ones (`sometimes`
     * fields). Login games stay out of every Reseller channel by having no
     * `reseller_code`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> field => message
     */
    public static function loginGameSettings(array $input, ?Game $game = null): array
    {
        $value = fn (string $key) => array_key_exists($key, $input) ? $input[$key] : $game?->{$key};
        $errors = [];

        if (filled($value('reseller_code'))) {
            $errors['reseller_code'] = 'A login game cannot have a reseller code (Reseller channels are UID-only).';
        }

        if ((bool) $value('player_validator_enabled')) {
            $errors['player_validator_enabled'] = 'Player validation does not apply to a login game.';
        }

        if ($value('delivery_mode') !== 'manual') {
            $errors['delivery_mode'] = 'A login game is delivered manually.';
        }

        if (blank($value('delivery_subtext'))) {
            $errors['delivery_subtext'] = 'A login game needs its own delivery subtext (the default promises 1–3 minutes).';
        }

        return $errors;
    }

    /**
     * Structural rules for each `credential_fields` item (decision 6); the
     * caller owns the list's own rule (`present` or `required`), plus
     * `array` and `max:`.self::MAX_FIELDS.
     *
     * @return array<string, mixed>
     */
    public static function schemaRules(string $prefix = 'credential_fields'): array
    {
        return [
            "{$prefix}.*.key" => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/', 'distinct'],
            "{$prefix}.*.label" => ['required', 'string', 'max:60'],
            "{$prefix}.*.type" => ['required', 'in:'.implode(',', self::FIELD_TYPES)],
            "{$prefix}.*.options" => ['nullable', 'required_if:'.$prefix.'.*.type,select', 'array', 'max:20'],
            "{$prefix}.*.options.*" => ['string', 'max:60', 'distinct'],
            "{$prefix}.*.required" => ['required', 'boolean'],
            "{$prefix}.*.secret" => ['required', 'boolean'],
            "{$prefix}.*.is_identifier" => ['required', 'boolean'],
        ];
    }

    /**
     * Cross-field rules: exactly one identifier, which is a required,
     * non-secret text or email field; a password is always secret.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public static function validateSchema(Validator $validator, array $fields, string $prefix = 'credential_fields'): void
    {
        if ($fields === []) {
            return;
        }

        $identifiers = array_keys(array_filter($fields, fn ($f) => ! empty($f['is_identifier'])));

        if (count($identifiers) !== 1) {
            $validator->errors()->add($prefix, 'Exactly one field must be the identifier.');

            return;
        }

        $id = $fields[$identifiers[0]];
        if (! in_array($id['type'] ?? null, ['text', 'email'], true) || ! empty($id['secret']) || empty($id['required'])) {
            $validator->errors()->add("{$prefix}.{$identifiers[0]}", 'The identifier must be a required, non-secret text or email field.');
        }

        foreach ($fields as $i => $field) {
            if (($field['type'] ?? null) === 'password' && empty($field['secret'])) {
                $validator->errors()->add("{$prefix}.{$i}.secret", 'A password field is always secret.');
            }
        }
    }

    /**
     * The stored shape: known keys only, booleans cast, options only on a
     * select.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public static function normalizeSchema(array $fields): array
    {
        return array_values(array_map(function (array $f) {
            $out = [
                'key' => $f['key'],
                'label' => $f['label'],
                'type' => $f['type'],
                'required' => (bool) $f['required'],
                'secret' => (bool) $f['secret'],
                'is_identifier' => (bool) $f['is_identifier'],
            ];

            if ($f['type'] === 'select') {
                $out['options'] = array_values($f['options'] ?? []);
            }

            return $out;
        }, $fields));
    }
}
