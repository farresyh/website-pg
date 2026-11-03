<?php

namespace App\Services\CsAssistant;

/**
 * ADR-136 decision 33: the one place customer-support text is masked, for
 * storage, model payloads and review alike. Order numbers are taken out
 * first (orderNumbers()), so masking never loses them.
 *
 * ponytail: patterns, not understanding. Names typed in free text are not
 * caught; a run of 5+ digits is treated as an identifier (player ID, phone,
 * account). Add patterns here when review shows a leak.
 */
final class CsTextRedactor
{
    private const ORDER = '/\bPG-[A-Z0-9]{6,}\b/i';

    /** @var array<string, string> pattern => placeholder, applied in order */
    private const PATTERNS = [
        self::ORDER => '[ORDER]',
        '/\bVC-[A-Z0-9]{6,}\b/i' => '[VOUCHER]',
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[EMAIL]',
        '/\+?\d[\d\s-]{6,}\d/' => '[NUMBER]',
        '/\b\d{5,}\b/' => '[NUMBER]',
    ];

    public static function redact(string $text): string
    {
        // Money like RM22.01 is kept: the decimal stops it reading as an identifier.
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /** @return list<string> */
    public static function orderNumbers(string $text): array
    {
        preg_match_all(self::ORDER, $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }
}
