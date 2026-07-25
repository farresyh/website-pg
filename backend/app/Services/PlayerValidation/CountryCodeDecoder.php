<?php

namespace App\Services\PlayerValidation;

/**
 * Decodes a flag emoji (e.g. "Malaysia 🇲🇾" or bare "🇲🇾") into its ISO
 * alpha-2 code algorithmically — a flag emoji is exactly two Unicode
 * Regional Indicator Symbols (U+1F1E6-U+1F1FF), one per letter, so no
 * country-name lookup table is needed. Chosen as the canonical
 * cross-provider signal since AcidGameShop (name + emoji) and Nexone
 * (emoji only) both carry it; MooGold's response has neither, so it
 * normalizes to null there, which is a real "no country data"
 * outcome the caller must handle, not an error.
 */
final class CountryCodeDecoder
{
    public static function fromFlagEmoji(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (! preg_match_all('/[\x{1F1E6}-\x{1F1FF}]/u', $text, $matches) || count($matches[0]) < 2) {
            return null;
        }

        [$first, $second] = array_slice($matches[0], 0, 2);

        return self::letterFromRegionalIndicator($first).self::letterFromRegionalIndicator($second);
    }

    private static function letterFromRegionalIndicator(string $char): string
    {
        return chr(ord('A') + (mb_ord($char, 'UTF-8') - 0x1F1E6));
    }
}
