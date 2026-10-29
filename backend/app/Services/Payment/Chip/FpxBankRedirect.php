<?php

namespace App\Services\Payment\Chip;

use Illuminate\Validation\Rule;

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
     * The one validation rule for the request field every flow shares: a
     * listed code, and only on the FPX channel (`$channelField` is the
     * request key holding the channel; membership calls it `payment_method`).
     *
     * @return list<mixed>
     */
    public static function rules(string $channelField = 'channel_code'): array
    {
        return ['nullable', 'string', "prohibited_unless:{$channelField},fpx", Rule::in(self::codes())];
    }

    /** One checkout URL (a stored `checkout_url`); unchanged for no or an unlisted bank. */
    public static function applyToUrl(?string $url, ?string $bankCode): ?string
    {
        // A paid purchase's URL is its invoice page, nothing to skip.
        if ($url === null || $bankCode === null || ! in_array($bankCode, self::codes(), true) || str_contains($url, '/invoice/')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?')
            .http_build_query(['preferred' => 'fpx', 'fpx_bank_code' => $bankCode]);
    }

    /**
     * @param  array<array-key, mixed>  $actions  the gateway's `actions`
     * @return array<array-key, mixed>
     */
    public static function apply(array $actions, ?string $bankCode): array
    {
        return array_map(static function (mixed $action) use ($bankCode) {
            if (is_array($action) && ($action['descriptor'] ?? null) === 'WEB_URL' && is_string($action['value'] ?? null)) {
                $action['value'] = self::applyToUrl($action['value'], $bankCode);
            }

            return $action;
        }, $actions);
    }
}
