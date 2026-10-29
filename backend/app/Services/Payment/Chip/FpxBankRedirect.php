<?php

namespace App\Services\Payment\Chip;

/**
 * ADR-130: sends the customer straight to the bank they picked on our page
 * by appending CHIP's Direct Post parameters to the checkout URL
 * (docs.chip-in.asia/chip-collect/overview/direct-post/fpx).
 *
 * The bank belongs to the click, not to the purchase, so this runs where the
 * URL leaves the API (order, membership and wallet responses) and the stored
 * `checkout_url` / `payment_ref` stay plain. CHIP accepts any code without
 * error (verified 2026-10-29), so the list in config/fpx-banks.json is the
 * only guard: an unlisted code leaves the URL untouched.
 */
final class FpxBankRedirect
{
    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(config('fpx-banks'), 'code');
    }

    /**
     * @param  array<array-key, mixed>  $actions  the gateway's `actions`
     * @return array<array-key, mixed>
     */
    public static function apply(array $actions, ?string $bankCode): array
    {
        if ($bankCode === null || ! in_array($bankCode, self::codes(), true)) {
            return $actions;
        }

        return array_map(static function (mixed $action) use ($bankCode) {
            $url = is_array($action) && ($action['descriptor'] ?? null) === 'WEB_URL' ? ($action['value'] ?? null) : null;

            // A paid purchase's URL is its invoice page, nothing to skip.
            if (! is_string($url) || str_contains($url, '/invoice/')) {
                return $action;
            }

            $action['value'] = $url.(str_contains($url, '?') ? '&' : '?')
                .http_build_query(['preferred' => 'fpx', 'fpx_bank_code' => $bankCode]);

            return $action;
        }, $actions);
    }
}
