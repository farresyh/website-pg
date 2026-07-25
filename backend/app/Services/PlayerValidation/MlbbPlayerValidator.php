<?php

namespace App\Services\PlayerValidation;

use Illuminate\Support\Facades\Log;

/**
 * MLBB's fallback chain — AcidGameShop -> Nexone -> MooGold, in that
 * priority order (the founder's own reliability ranking). Each
 * provider's ProviderUnavailableException is caught and logged, then
 * the next is tried; only when every provider is unavailable does
 * this itself throw. Callers treat that identically to
 * "region_unknown" (per the founder's decision): block checkout,
 * direct the customer to contact support.
 *
 * Implements PlayerValidator itself (not just an internal helper) so
 * PlayerValidatorRegistry can bind the whole chain under one key
 * ("mlbb") exactly like it would a single provider.
 */
final class MlbbPlayerValidator implements PlayerValidator
{
    /**
     * @param  PlayerValidator[]  $providers  ordered by priority
     */
    public function __construct(private readonly array $providers) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        $lastException = null;

        foreach ($this->providers as $provider) {
            try {
                return $provider->validate($playerId, $serverId);
            } catch (ProviderUnavailableException $e) {
                $lastException = $e;

                Log::warning('Player validator provider unavailable, falling back', [
                    'provider' => $provider::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw new ProviderUnavailableException(
            'All MLBB player-ID validation providers are unavailable',
            previous: $lastException,
        );
    }
}
