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
    public function __construct(
        private readonly array $providers,
        // 2026-09-29 audit K-2: without it, three providers each running to
        // their own timeout held a php-fpm worker ~32s. Checked before each
        // fallback: a first provider that times out (8s) ends the chain; one
        // that fails fast still gets a fallback (~17s worst realistic case).
        private readonly int $deadlineSeconds = 8,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        $lastException = null;
        $deadline = now()->addSeconds($this->deadlineSeconds);

        foreach ($this->providers as $provider) {
            if ($lastException !== null && now()->gte($deadline)) {
                break;
            }

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
