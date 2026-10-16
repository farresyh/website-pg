<?php

namespace App\Services\PlayerValidation;

/**
 * For a game whose region is a server picked as a zone (Genshin, HSR, ZZZ:
 * ADR-005 2026-10-16 third addendum), the UID's real server must be the
 * zone the customer chose, and one this game sells. The one rule
 * PlayerValidationController and the reseller bot both apply.
 */
final class ZoneCheck
{
    /**
     * @param  list<string>|null  $zoneOptions  the game's `zone_options`
     * @return 'valid'|'wrong_zone'|null null = not a zone check (an invalid
     *                                   ID, or a country-based validator)
     */
    public static function state(PlayerValidationResult $result, ?string $chosenZone, ?array $zoneOptions): ?string
    {
        if (! $result->valid || $result->server === null) {
            return null;
        }

        $zone = self::sellableZone($result, $zoneOptions);

        return $zone !== null && $zone === $chosenZone ? 'valid' : 'wrong_zone';
    }

    /**
     * The UID's zone when this game sells it, else null (a server we
     * don't sell here).
     *
     * @param  list<string>|null  $zoneOptions
     */
    public static function sellableZone(PlayerValidationResult $result, ?array $zoneOptions): ?string
    {
        return $result->zone !== null && in_array($result->zone, $zoneOptions ?? [], true) ? $result->zone : null;
    }
}
