<?php

namespace App\Support;

/**
 * ADR-116 decision 8: one canonical digits-only form (MSISDN, no `+`) for a
 * customer-typed phone number. Checkout stores whatever the customer typed
 * (`012-345 6789`, `+60123456789`, `60123456789`), and that raw value also
 * goes to suppliers, so it is never rewritten. This is applied only where
 * two numbers must be compared or a WhatsApp chat id is built.
 *
 * A leading `00` is an international dialling prefix and is dropped. A
 * single leading `0` is a Malaysian trunk prefix and becomes `60`. Anything else
 * is taken to already carry its country code. Returns null for a value
 * that can't be a real number (E.164 allows 8–15 digits here).
 */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2); // international dialling prefix, e.g. 0060…
        } elseif (str_starts_with($digits, '0')) {
            $digits = '60'.substr($digits, 1);
        }

        $length = strlen($digits);

        return $length >= 8 && $length <= 15 ? $digits : null;
    }

    /** True when both normalise to the same number; false when either can't be normalised. */
    public static function same(?string $a, ?string $b): bool
    {
        $a = self::normalize($a);

        return $a !== null && $a === self::normalize($b);
    }
}
