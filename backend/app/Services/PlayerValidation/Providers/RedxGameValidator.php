<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\CountryCodeDecoder;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RedxGame's public ID checker (redxgame.com/tools, found 2026-10-16 after
 * AcidGameShop and Nexone stopped working, ADR-005 2026-10-16 addendum).
 * Unofficial: it is the JSON call their own checker page makes.
 *
 * It is a Laravel app, so a check needs a session cookie and the page's
 * CSRF token. Both are cached and reused, so a check is normally one
 * request; a rejected session (419, a redirect page instead of JSON) is
 * renewed once.
 *
 * One instance per Redx game (`mobile-legends`, `free-fire`); they share
 * the one cached session, which belongs to the site, not a game.
 *
 * `status: false` is the same "Couldn't verify that ID" for a wrong ID and
 * for a failure on Redx's own side. Where a second provider exists (MLBB:
 * MooGold) it is thrown as unavailable so that provider decides; where
 * none does (Free Fire) it is an invalid ID the customer can correct
 * (founder's calls, 2026-10-16).
 */
final class RedxGameValidator implements PlayerValidator
{
    public const SESSION_CACHE_KEY = 'player-validator:redxgame:session';

    /** Under Laravel's default 120-minute session lifetime. */
    private const SESSION_TTL_SECONDS = 3600;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5,
        private readonly string $game = 'mobile-legends',
        private readonly bool $statusFalseMeansInvalid = false,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        $session = Cache::get(self::SESSION_CACHE_KEY);
        $renewed = false;

        if (! is_array($session)) {
            $session = $this->startSession();
            $renewed = true;
        }

        $response = $this->check($session, $playerId, $serverId);

        if (! $this->isCheckResponse($response) && ! $renewed) {
            $response = $this->check($this->startSession(), $playerId, $serverId);
        }

        if (! $this->isCheckResponse($response)) {
            Cache::forget(self::SESSION_CACHE_KEY);

            throw new ProviderUnavailableException("RedxGame check returned HTTP {$response->status()} without a check result");
        }

        if ($response->json('status') !== true) {
            if ($this->statusFalseMeansInvalid) {
                return PlayerValidationResult::invalid('redxgame');
            }

            throw new ProviderUnavailableException('RedxGame could not verify the ID: '.$response->json('message'));
        }

        $region = $response->json('region');
        $countryCode = CountryCodeDecoder::fromCountryName($region);

        if ($countryCode === null && filled($region)) {
            Log::warning('RedxGame region not mapped to a country code', ['region' => $region]);
        }

        return PlayerValidationResult::valid(
            provider: 'redxgame',
            nickname: $response->json('nickname'),
            countryCode: $countryCode,
        );
    }

    /**
     * @return array{cookies: array<int, array<string, mixed>>, token: string}
     */
    private function startSession(): array
    {
        $jar = new CookieJar;

        try {
            $page = $this->client($jar)->get($this->checkerPath());
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('RedxGame checker page request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $page->successful() || ! preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page->body(), $match)) {
            throw new ProviderUnavailableException("RedxGame checker page returned HTTP {$page->status()} without a CSRF token");
        }

        $session = ['cookies' => $jar->toArray(), 'token' => $match[1]];
        Cache::put(self::SESSION_CACHE_KEY, $session, self::SESSION_TTL_SECONDS);

        return $session;
    }

    /**
     * @param  array{cookies: array<int, array<string, mixed>>, token: string}  $session
     */
    private function check(array $session, string $playerId, ?string $serverId): Response
    {
        try {
            return $this->client(new CookieJar(false, $session['cookies']))
                ->withHeaders([
                    'X-CSRF-TOKEN' => $session['token'],
                    'Referer' => rtrim($this->baseUrl, '/').$this->checkerPath(),
                ])
                ->post('/tools/check', [
                    'game' => $this->game,
                    'inputs' => $serverId === null
                        ? ['user_id' => $playerId]
                        : ['user_id' => $playerId, 'server_id' => $serverId],
                ]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('RedxGame check request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * A 419 or the HTML redirect Redx sends for a missing session has no
     * boolean `status`.
     */
    private function isCheckResponse(Response $response): bool
    {
        return $response->successful() && is_bool($response->json('status'));
    }

    private function checkerPath(): string
    {
        return "/tools/{$this->game}-id-checker";
    }

    private function client(CookieJar $jar): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(3)
            ->acceptJson()
            ->withOptions(['cookies' => $jar]);
    }
}
