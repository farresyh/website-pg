<?php

namespace App\Services\Supplier\RequestLog;

/**
 * ADR-054 decision 7 — a request-scoped ambient flag, not a
 * SupplierAdapter parameter: every adapter method already hardcodes
 * its own literal `call_type` string at its `client()` call site (see
 * GamevionAdapter/DigiflazzAdapter), so there's no existing seam to
 * pass "this call originated from the Developer Tool" through without
 * reshaping the adapter interface itself (ADR-054 decision 2 — that
 * seam stays stable). SupplierRequestLogger reads this flag instead,
 * at the one place every real adapter call already funnels through.
 */
final class DeveloperTestContext
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runIn(callable $callback): mixed
    {
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = false;
        }
    }
}
