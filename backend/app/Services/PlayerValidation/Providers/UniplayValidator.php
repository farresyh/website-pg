<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * UniPlay's public region checker (uniplay.id/cek-region), the MLBB
 * fallback after RedxGame (ADR-005 2026-10-28 addendum). Unofficial: it is
 * the JSON call their own page makes. That call needs a Basic
 * Authorization header which their page embeds for every visitor, so the
 * token is read from the page, cached, and re-read once if rejected.
 *
 * It is the last provider in the chain, so `status: false` is an invalid
 * ID; a transport error, 5xx or a missing token is unavailable.
 */
final class UniplayValidator implements PlayerValidator
{
    public const TOKEN_CACHE_KEY = 'player-validator:uniplay:token';

    private const PAGE = '/cek-region';

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);
        $renewed = false;

        if (! is_string($token)) {
            $token = $this->fetchToken();
            $renewed = true;
        }

        $response = $this->check($token, $playerId, $serverId);

        if (in_array($response->status(), [401, 403], true) && ! $renewed) {
            $response = $this->check($this->fetchToken(), $playerId, $serverId);
        }

        if (! $response->successful() || ! is_bool($response->json('status'))) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            throw new ProviderUnavailableException("UniPlay check returned HTTP {$response->status()} without a check result");
        }

        if ($response->json('status') !== true) {
            return PlayerValidationResult::invalid('uniplay');
        }

        return PlayerValidationResult::valid(
            provider: 'uniplay',
            nickname: $response->json('nickname'),
            countryCode: $response->json('region'),
        );
    }

    private function fetchToken(): string
    {
        try {
            $page = Http::baseUrl($this->baseUrl)->timeout($this->timeoutSeconds)->connectTimeout(3)->get(self::PAGE);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('UniPlay checker page request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $page->successful() || ! preg_match('/"Authorization"\s*:\s*"(Basic [A-Za-z0-9+\/=]+)"/', $page->body(), $match)) {
            throw new ProviderUnavailableException("UniPlay checker page returned HTTP {$page->status()} without a token");
        }

        Cache::put(self::TOKEN_CACHE_KEY, $match[1], 3600);

        return $match[1];
    }

    private function check(string $token, string $playerId, ?string $serverId): Response
    {
        try {
            return Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(3)
                ->acceptJson()
                ->asForm()
                ->withHeaders(['Authorization' => $token, 'Referer' => $this->baseUrl.self::PAGE])
                ->post('/ajax/check-region.php', ['user_id' => $playerId, 'server_id' => $serverId]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('UniPlay check request failed: '.$e->getMessage(), 0, $e);
        }
    }
}
