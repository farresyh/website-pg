<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\CountryCodeDecoder;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * AcidGameShop's unofficial MLBB validate endpoint (docs/prd.md's
 * research, confirmed live 2026-07-25) — the cleanest of the three:
 * stateless JSON in/out, no token/cookie handshake needed. First in
 * the fallback chain (MlbbPlayerValidator) for that reason.
 */
final class AcidGameShopValidator implements PlayerValidator
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 8,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post('/api/validate-mlbb', [
                    'id' => $playerId,
                    'server' => $serverId,
                    'type' => 'ml2',
                ]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('AcidGameShop request failed: '.$e->getMessage(), 0, $e);
        }

        // Confirmed live: a 400 carrying {"error": "..."} is
        // AcidGameShop's own "invalid ID or server" signal — a real
        // negative result, not an unreachable-provider case.
        if ($response->status() === 400 && $response->json('error') !== null) {
            return PlayerValidationResult::invalid('acidgameshop');
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException("AcidGameShop returned unexpected HTTP {$response->status()}");
        }

        $nickname = $response->json('nickname');

        if ($nickname === null) {
            throw new ProviderUnavailableException('AcidGameShop response missing nickname on a 200');
        }

        return PlayerValidationResult::valid(
            provider: 'acidgameshop',
            nickname: $nickname,
            countryCode: CountryCodeDecoder::fromFlagEmoji($response->json('country')),
        );
    }
}
