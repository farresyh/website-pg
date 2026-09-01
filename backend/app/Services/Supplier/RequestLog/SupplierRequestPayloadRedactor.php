<?php

namespace App\Services\Supplier\RequestLog;

use App\Services\Supplier\SupplierConfigSchema;

/**
 * ADR-051 decision 4 / foundation-security.md §7: redacts a supplier
 * HTTP call's headers/body *before* it ever reaches the queue or the
 * database — never a post-hoc scrub on read. Reuses
 * SupplierConfigSchema's 'secret' classification (the same fields
 * SupplierController::visibleConfig() already never serializes) as
 * the body-key source of truth, plus a small per-slug list for values
 * derived from a secret rather than the secret itself.
 */
final class SupplierRequestPayloadRedactor
{
    private const MASK = '[REDACTED]';

    /** Header names (case-insensitive) every adapter's client() uses to carry auth, regardless of supplier. */
    private const SECRET_HEADERS = ['authorization', 'x-api-key'];

    /**
     * Body keys that are sensitive without being one of
     * SupplierConfigSchema's own 'secret' api_config keys — e.g.
     * Digiflazz's `sign` (an MD5 of username+apiKey+suffix): not the
     * raw credential, but replayable for that exact call, so it's
     * redacted with the same discipline rather than treated as safe
     * because it isn't literally the api_key.
     */
    private const EXTRA_SECRET_BODY_KEYS = [
        'digiflazz' => ['sign'],
    ];

    /**
     * @param  array<string, string[]>  $headers  PSR-7 shape (each header maps to an array of values)
     * @return array<string, string[]>
     */
    public static function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $key => $values) {
            $redacted[$key] = in_array(strtolower($key), self::SECRET_HEADERS, true)
                ? [self::MASK]
                : $values;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    public static function redactBody(string $slug, ?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $secretKeys = collect(SupplierConfigSchema::fieldsFor($slug))
            ->filter(fn (string $type) => $type === 'secret')
            ->keys()
            ->merge(self::EXTRA_SECRET_BODY_KEYS[$slug] ?? [])
            ->all();

        foreach ($secretKeys as $key) {
            if (array_key_exists($key, $body)) {
                $body[$key] = self::MASK;
            }
        }

        return $body;
    }
}
