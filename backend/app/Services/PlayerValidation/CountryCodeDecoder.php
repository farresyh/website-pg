<?php

namespace App\Services\PlayerValidation;

use DateTimeZone;
use Locale;

/**
 * Maps an English country name (RedxGame's `region`, e.g. "Malaysia")
 * to its ISO alpha-2 code, the key `player_region_mappings` uses.
 *
 * Names come from ICU (`intl`), so no hand-kept table. The candidate
 * codes come from the timezone database, which lists only codes in
 * current use: ICU still names retired codes (BU, SU, DD, YU) with
 * today's country names, so walking AA..ZZ would map "Myanmar" to BU.
 * An unknown name is null, which the caller treats as region unknown
 * (checkout blocked), never a guess.
 */
final class CountryCodeDecoder
{
    /**
     * Names RedxGame uses that ICU spells differently (confirmed with a
     * real account 2026-10-16). Add one when a "region not mapped" log
     * line shows a new spelling.
     */
    private const ALIASES = [
        'turkey' => 'TR',
    ];

    /** @var array<string, string>|null */
    private static ?array $byName = null;

    public static function fromCountryName(?string $name): ?string
    {
        $key = self::normalise((string) $name);

        if ($key === '') {
            return null;
        }

        return self::byName()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function byName(): array
    {
        if (self::$byName !== null) {
            return self::$byName;
        }

        $map = self::ALIASES;

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $code = (new DateTimeZone($zone))->getLocation()['country_code'] ?? '??';

            if ($code !== '??') {
                $map[self::normalise(Locale::getDisplayRegion("-{$code}", 'en'))] ??= $code;
            }
        }

        return self::$byName = $map;
    }

    /**
     * Lower-case, and drop ICU's parenthetical ("Myanmar (Burma)").
     */
    private static function normalise(string $name): string
    {
        return trim(mb_strtolower(preg_replace('/\s*\(.*\)\s*/u', ' ', $name)));
    }
}
