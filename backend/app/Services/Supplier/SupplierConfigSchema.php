<?php

namespace App\Services\Supplier;

/**
 * ADR-046 decision 3, extracted (previously a private const duplicated
 * only in intent, not in code, across SupplierController and
 * AppServiceProvider — see the fix/supplier-config-guard branch that
 * introduced this class). Single source of truth for which
 * `api_config` keys a supplier slug needs and how each is classified
 * (`text`/`secret`/`boolean`). Mirrored (not shared — different
 * language) by the frontend's SUPPLIER_FIELD_DEFINITIONS
 * (admin/src/lib/suppliers.ts); keep both in sync by hand when a
 * supplier's real field shape changes.
 */
final class SupplierConfigSchema
{
    private const DEFINITIONS = [
        'gamevion' => ['base_url' => 'text', 'bearer_token' => 'secret', 'api_key' => 'secret', 'sandbox' => 'boolean'],
        'digiflazz' => ['base_url' => 'text', 'username' => 'secret', 'api_key' => 'secret', 'testing' => 'boolean', 'customer_no_separator' => 'text'],
    ];

    /**
     * @return array<string, string> key => 'text'|'secret'|'boolean'
     */
    public static function fieldsFor(string $slug): array
    {
        return self::DEFINITIONS[$slug] ?? [];
    }

    /**
     * Every key an adapter binding (AppServiceProvider) reads directly
     * off `$apiConfig[...]` without a `??` fallback — so a key simply
     * absent from a partially-filled config, not just an empty array,
     * is exactly what must be caught before construction, not after.
     *
     * @return list<string> keys defined for this slug but missing from $config
     */
    public static function missingKeys(string $slug, array $config): array
    {
        return array_values(array_diff(array_keys(self::fieldsFor($slug)), array_keys($config)));
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
