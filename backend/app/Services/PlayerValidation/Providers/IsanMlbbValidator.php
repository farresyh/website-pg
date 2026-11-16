<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * isan's MLBB lookup (`mlbb-api.isan.eu.org/find`, the endpoint behind the
 * open-source `ihsangan/valid` API), first in MLBB's chain, ahead of
 * UniPlay (ADR-005 2026-11-16 addendum). Unofficial, run by one
 * developer, on Vercel behind a WAF: no key, no SLA.
 *
 * Only its explicit "not found" (404 with `success: false`) is an invalid
 * ID. Anything else that isn't a found player (WAF page, 5xx, timeout, an
 * unexpected body) is unavailable, so the next provider decides.
 */
final class IsanMlbbValidator implements PlayerValidator
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        if ($serverId === null || $serverId === '') {
            return PlayerValidationResult::invalid('isan');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(3)
                ->acceptJson()
                ->get('/find', ['id' => $playerId, 'zone' => $serverId]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('isan check request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404 && $response->json('success') === false) {
            return PlayerValidationResult::invalid('isan');
        }

        $name = $response->json('name');

        if (! $response->successful() || $response->json('success') !== true || ! is_string($name) || $name === '') {
            throw new ProviderUnavailableException("isan check returned HTTP {$response->status()} without a check result");
        }

        $country = $response->json('countryCode');

        return PlayerValidationResult::valid(
            provider: 'isan',
            nickname: $name,
            countryCode: is_string($country) && strlen($country) === 2 ? strtoupper($country) : null,
        );
    }
}
