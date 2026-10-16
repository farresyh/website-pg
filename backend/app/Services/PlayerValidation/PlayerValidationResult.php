<?php

namespace App\Services\PlayerValidation;

/**
 * Canonical shape every player-ID validator (and the fallback chain
 * that wraps them) normalizes into — mirrors SupplierResponse's role
 * for Supplier Adapters. `countryCode` is ISO alpha-2 or null: not
 * every provider returns country data (MooGold sometimes omits it
 * entirely), and that absence is a real, distinct case from an
 * invalid ID — callers must not conflate "valid but region unknown"
 * with "invalid".
 *
 * `server`/`zone` are set instead for a game whose region is a game
 * server picked as a zone (Genshin, HSR, ZZZ): `server` is the provider's
 * label ("Americas"), `zone` our zone value for it ("America"), null for
 * a server we don't sell. See ZoneCheck.
 */
final class PlayerValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $nickname,
        public readonly ?string $countryCode,
        public readonly string $provider,
        public readonly ?string $server = null,
        public readonly ?string $zone = null,
    ) {}

    public static function valid(string $provider, ?string $nickname, ?string $countryCode, ?string $server = null, ?string $zone = null): self
    {
        return new self(true, $nickname, $countryCode, $provider, $server, $zone);
    }

    public static function invalid(string $provider): self
    {
        return new self(false, null, null, $provider);
    }
}
