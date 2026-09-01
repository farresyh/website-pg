<?php

namespace App\Support;

/**
 * ADR-065: the single server-side masking implementation for the guest
 * order-status view (`TrackOrderController` + `OrderStatusUpdated`).
 *
 * The full `customer_*` values never leave the backend on that surface —
 * the `order_number` is only proof-of-ownership (ADR-011), so a forwarded
 * status link must not hand a stranger usable contact details. Masked
 * output is a recognition aid for the buyer, who typed these at checkout.
 *
 * Not an Eloquent accessor and not applied on the model — every other
 * path (admin, checkout, fulfilment, notifications, ledger) keeps the
 * raw values.
 */
final class ContactMask
{
    /** `John Doe` -> `John D.`; a single-token name is returned as-is. */
    public static function name(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = end($parts);

        return $parts[0].' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }

    /** `john@gmail.com` -> `j••••@gmail.com`. */
    public static function email(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return $email === '' ? null : $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $first = mb_substr($local, 0, 1);

        return $first.'••••@'.$domain;
    }

    /** `0123456789` -> `01•-•••-•789`; keeps the leading 2 and trailing 3 digits. */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (mb_strlen($digits) <= 5) {
            return str_repeat('•', mb_strlen($digits));
        }

        return mb_substr($digits, 0, 2).'•-•••-•'.mb_substr($digits, -3);
    }
}
