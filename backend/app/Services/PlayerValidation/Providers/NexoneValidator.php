<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\CountryCodeDecoder;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Nexone.ph's unofficial MLBB ID-checker (docs/prd.md's research,
 * confirmed live 2026-07-25). Unlike AcidGameShop, this is a
 * session-bound form flow: `/idchecker` issues a one-time
 * `checkToken` tied to that request's cookies, which must be replayed
 * on the actual check call — a fresh CookieJar per validate() call
 * carries the cookie from step 1 into step 2. Second in the fallback
 * chain: the extra round-trip is only ever paid when AcidGameShop is
 * unavailable.
 */
final class NexoneValidator implements PlayerValidator
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 8,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        $jar = new CookieJar;

        try {
            $page = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->withOptions(['cookies' => $jar])
                ->get('/idchecker');
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('Nexone token page request failed: '.$e->getMessage(), 0, $e);
        }

        if ($page->failed()) {
            throw new ProviderUnavailableException("Nexone token page returned HTTP {$page->status()}");
        }

        if (! preg_match('/id="checkToken"[^>]*value="([a-f0-9]+)"/i', $page->body(), $tokenMatch)) {
            throw new ProviderUnavailableException('Nexone checkToken not found in idchecker page — page markup likely changed');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->asForm()
                ->withOptions(['cookies' => $jar])
                ->withHeaders(['Referer' => "{$this->baseUrl}/idchecker"])
                ->post('/pages/get_check_id', [
                    'userId' => $playerId,
                    'zoneId' => $serverId,
                    'checkToken' => $tokenMatch[1],
                ]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('Nexone check request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException("Nexone check returned unexpected HTTP {$response->status()}");
        }

        if ($response->json('success') !== true) {
            return PlayerValidationResult::invalid('nexone');
        }

        return PlayerValidationResult::valid(
            provider: 'nexone',
            nickname: $response->json('nickname'),
            countryCode: CountryCodeDecoder::fromFlagEmoji($response->json('region')),
        );
    }
}
