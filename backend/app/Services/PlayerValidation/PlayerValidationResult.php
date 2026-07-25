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
 */
final class PlayerValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $nickname,
        public readonly ?string $countryCode,
        public readonly string $provider,
    ) {}

    public static function valid(string $provider, ?string $nickname, ?string $countryCode): self
    {
        return new self(true, $nickname, $countryCode, $provider);
    }

    public static function invalid(string $provider): self
    {
        return new self(false, null, null, $provider);
    }
}
