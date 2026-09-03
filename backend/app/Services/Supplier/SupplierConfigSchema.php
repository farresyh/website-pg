<?php

namespace App\Services\Supplier;

/**
 * ADR-046 decision 3, extracted (previously a private const duplicated
 * only in intent, not in code, across SupplierController and
 * AppServiceProvider — see the fix/supplier-config-guard branch that
 * introduced this class). Single source of truth for which
 * `api_config` keys a supplier slug needs and how each is classified
 * (`text`/`secret`/`boolean`/`list`). Mirrored (not shared — different
 * language) by the frontend's SUPPLIER_FIELD_DEFINITIONS
 * (admin/src/lib/suppliers.ts); keep both in sync by hand when a
 * supplier's real field shape changes.
 */
final class SupplierConfigSchema
{
    /**
     * Keys an adapter binding (AppServiceProvider) reads directly off
     * `$apiConfig[...]` with NO `??` fallback — a key absent from a
     * partially-filled config must be caught before construction.
     * `missingKeys()` / the "fully configured" gate check exactly these.
     */
    private const REQUIRED = [
        'gamevion' => ['base_url' => 'text', 'bearer_token' => 'secret', 'api_key' => 'secret', 'sandbox' => 'boolean'],
        'digiflazz' => ['base_url' => 'text', 'username' => 'secret', 'api_key' => 'secret', 'testing' => 'boolean', 'customer_no_separator' => 'text'],
    ];

    /**
     * Keys the edit form renders but that a sync/adapter reads WITH a
     * fallback — their absence never blocks the adapter, so they are
     * NOT part of the "fully configured" gate. ADR-067 decision 2:
     * `digiflazz.category_whitelist` (a `list` — comma-separated in the
     * form, `string[]` in `api_config`; empty/absent = sync every
     * category, unchanged pre-ADR-067 behaviour).
     */
    private const OPTIONAL = [
        // ADR-069 decision 8 — `webhook_secret` is irrelevant to the
        // outbound API (createOrder/checkBalance sign per-endpoint with
        // username+api_key), so it must NOT gate "fully configured" or
        // the adapter binding; it only matters to
        // DigiflazzWebhookController, which hard-rejects when it is
        // absent. `secret`-typed so the redactor + the masked-merge
        // form treat it like any other credential.
        //
        // ADR-069 decision 13 — `low_balance_threshold` (a bare number
        // in the supplier's own balance currency) drives the daily
        // app:refresh-supplier-balances warning + the Dashboard Health
        // chip. Absent = no warning.
        'digiflazz' => [
            'category_whitelist' => 'list',
            'webhook_secret' => 'secret',
            'low_balance_threshold' => 'text',
        ],
        'gamevion' => ['low_balance_threshold' => 'text'],
    ];

    /**
     * Every field for the edit form + the redactor — required and
     * optional together.
     *
     * @return array<string, string> key => 'text'|'secret'|'boolean'|'list'
     */
    public static function fieldsFor(string $slug): array
    {
        return array_merge(self::REQUIRED[$slug] ?? [], self::OPTIONAL[$slug] ?? []);
    }

    /**
     * Every key an adapter binding (AppServiceProvider) reads directly
     * off `$apiConfig[...]` without a `??` fallback — so a key simply
     * absent from a partially-filled config, not just an empty array,
     * is exactly what must be caught before construction, not after.
     * Optional fields (ADR-067) are deliberately excluded.
     *
     * @return list<string> required keys defined for this slug but missing from $config
     */
    public static function missingKeys(string $slug, array $config): array
    {
        return array_values(array_diff(array_keys(self::REQUIRED[$slug] ?? []), array_keys($config)));
    }

    /**
     * Coerce every `list`-type field to a real `string[]` — the edit
     * form may submit it as a raw comma-separated string, and a direct
     * API call could send anything. Trims entries, drops blanks. A
     * value that is neither string nor array becomes `[]` — fail safe
     * to "no filter", never "filter everything out" (ADR-067). Keys not
     * present in $config are left untouched (a partial update must not
     * resurrect a field the caller didn't send).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalizeConfig(string $slug, array $config): array
    {
        foreach (self::fieldsFor($slug) as $key => $type) {
            if ($type !== 'list' || ! array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];

            if (is_string($value)) {
                $value = explode(',', $value);
            }

            $config[$key] = is_array($value)
                ? array_values(array_filter(array_map('trim', $value), static fn ($v) => $v !== ''))
                : [];
        }

        return $config;
    }

    /**
     * ADR-046 addendum — whichever key is marked 'boolean' for this
     * slug *is* its sandbox/testing-mode flag, by this codebase's own
     * convention (mirrored by the frontend's SUPPLIER_FIELD_DEFINITIONS).
     * Extracted here (previously private to SupplierController) so
     * ADR-054's Developer API Tester can gate `createOrder` on the same
     * value without duplicating the lookup. Null when a supplier has no
     * such field at all, not false — "unknown" and "definitely
     * production" are different things, and callers gating a real
     * side-effect on this should treat null as "not confirmed sandbox."
     */
    public static function isSandbox(string $slug, array $apiConfig): ?bool
    {
        $booleanKey = array_search('boolean', self::fieldsFor($slug), true);

        if ($booleanKey === false) {
            return null;
        }

        return isset($apiConfig[$booleanKey]) ? (bool) $apiConfig[$booleanKey] : null;
    }
}
