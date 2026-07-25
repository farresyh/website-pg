<?php

namespace App\Services\PlayerValidation;

/**
 * Common interface every player-ID validation source implements —
 * both the individual third-party providers (AcidGameShop, Nexone,
 * MooGold) and the fallback-chain orchestrator (MlbbPlayerValidator)
 * that sits in front of them, since the chain is itself resolved by
 * PlayerValidatorRegistry the same way a single provider would be.
 */
interface PlayerValidator
{
    /**
     * @throws ProviderUnavailableException when this specific source
     *                                      could not be reached or its response could not be
     *                                      parsed — distinct from a confirmed-invalid ID. Callers
     *                                      (the fallback chain) catch this to try the next source;
     *                                      they must never catch it to mean "invalid ID".
     */
    public function validate(string $playerId, ?string $serverId): PlayerValidationResult;
}
