<?php

namespace App\Services\PlayerCheck;

use App\Models\Affiliate;
use App\Models\AffiliateGame;
use App\Models\Game;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidatorProfile;
use App\Models\PublicPlayerCheck;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Checkout\CheckoutInputValidator;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use App\Support\MalaysiaTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The public Game ID Checker (ADR-123). A family is a validator profile;
 * the answer is the store we sell for that account, never the provider's
 * raw country or text. Checkout's `validate-player` path is untouched:
 * this service has its own validator binding (RedxGame only), cache,
 * cooldown, hourly budget and table, so its traffic can't spend
 * checkout's provider and can't approve a payment.
 */
class PublicPlayerCheckService
{
    public const STATUS_FOUND = 'found';

    public const STATUS_UNSUPPORTED_REGION = 'unsupported_region';

    public const STATUS_UNAVAILABLE_HERE = 'unavailable_here';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_BUSY = 'busy';

    private const COOLDOWN_KEY = 'player-check:cooldown';

    public function __construct(
        private readonly PlayerValidatorRegistry $registry,
        private readonly CheckoutInputValidator $inputValidator,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    /**
     * Families listed on this brand (ADR-123 decision 2).
     *
     * @return list<array{slug: string, name: string, image_url: ?string, player_id_format: string, requires_server_id: bool}>
     */
    public function families(Affiliate $brand): array
    {
        $hidden = AffiliateGame::hiddenGameIdsFor($brand->id);

        return PlayerValidatorProfile::query()
            ->whereNotNull('public_slug')
            ->orderBy('public_name')
            ->get()
            ->map(fn (PlayerValidatorProfile $profile) => $this->family($profile, $hidden))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{slug: string, name: string, image_url: ?string, player_id_format: string, requires_server_id: bool}|null
     */
    public function findFamily(Affiliate $brand, string $slug): ?array
    {
        $profile = PlayerValidatorProfile::query()->where('public_slug', $slug)->first();

        return $profile === null ? null : $this->family($profile, AffiliateGame::hiddenGameIdsFor($brand->id));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException on a malformed ID, before any provider call
     */
    public function check(Affiliate $brand, string $slug, string $playerId, ?string $serverId): array
    {
        $profile = PlayerValidatorProfile::query()->where('public_slug', $slug)->firstOrFail();
        $hidden = AffiliateGame::hiddenGameIdsFor($brand->id);
        $stores = $this->stores($profile, $hidden);
        $family = $this->family($profile, $hidden, $stores);

        if ($family === null) {
            abort(404);
        }

        // Every store in a listed family shares one input shape, so the
        // first one speaks for all of them.
        $reference = $stores->first();
        if ($error = $this->inputValidator->validateWithoutZone($reference, $playerId, $serverId)) {
            throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
        }

        $serverId = $family['requires_server_id'] ? $serverId : null;
        [$result, $cacheHit] = $this->lookup($profile, $playerId, $serverId);

        $answer = $result === null
            ? ['status' => self::STATUS_BUSY, 'nickname' => null, 'zone' => null, 'stores' => [], 'matched_game_id' => null]
            : $this->answer($profile, $result, $hidden);

        PublicPlayerCheck::query()->create([
            'player_validator_profile_id' => $profile->id,
            'player_id' => $playerId,
            'server_id' => $serverId,
            'status' => $answer['status'],
            'nickname' => $answer['nickname'],
            'matched_game_id' => $answer['matched_game_id'],
            'cache_hit' => $cacheHit,
        ]);

        unset($answer['matched_game_id']);

        return $answer + ['player_id' => $playerId, 'server_id' => $answer['zone'] ?? $serverId];
    }

    /**
     * Cached result, else one provider call if the cooldown and the hourly
     * budget allow it. Null = busy.
     *
     * @return array{0: ?PlayerValidationResult, 1: bool}
     */
    private function lookup(PlayerValidatorProfile $profile, string $playerId, ?string $serverId): array
    {
        $cacheKey = 'player-check:result:'.$profile->id.':'.sha1($playerId.'|'.($serverId ?? ''));

        if (is_array($cached = Cache::get($cacheKey))) {
            return [$this->fromCache($cached), true];
        }

        if (Cache::has(self::COOLDOWN_KEY) || ! $this->spendBudget()) {
            return [null, false];
        }

        try {
            $result = $this->registry->resolvePublic($profile->key)->validate($playerId, $serverId);
        } catch (UnsupportedPlayerValidatorException|ProviderUnavailableException $e) {
            Log::warning('Game ID Checker provider failed; cooling down', ['profile' => $profile->key, 'error' => $e->getMessage()]);
            Cache::put(self::COOLDOWN_KEY, true, (int) config('services.player_check.cooldown_seconds'));

            return [null, false];
        }

        Cache::put($cacheKey, [
            'valid' => $result->valid,
            'nickname' => $result->nickname,
            'country_code' => $result->countryCode,
            'provider' => $result->provider,
            'server' => $result->server,
            'zone' => $result->zone,
        ], now()->addMinutes((int) config('services.player_check.cache_minutes')));

        return [$result, false];
    }

    /**
     * Reserve one provider call from this clock hour's budget, shared by
     * every brand. The first refusal of a Malaysia day emails the admins.
     */
    private function spendBudget(): bool
    {
        $key = 'player-check:budget:'.now()->format('YmdH');
        Cache::add($key, 0, 3700);

        $budget = (int) config('services.player_check.hourly_budget');
        if (Cache::increment($key) <= $budget) {
            return true;
        }

        $day = now()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString();
        if (Cache::add("player-check:budget-alert:{$day}", true, 172800)) {
            $this->alerter->alert(
                'Hourly budget spent',
                "The public Game ID Checker used its {$budget} provider calls this hour and is answering \"busy\" until the hour ends. "
                    .'Either the page is popular (raise PLAYER_CHECK_HOURLY_BUDGET) or it is being abused (check public_player_checks).',
                'Game ID Checker',
            );
        }

        return false;
    }

    /**
     * @return array{status: string, nickname: ?string, zone: ?string, stores: list<array{slug: string, name: string}>, matched_game_id: ?int}
     */
    private function answer(PlayerValidatorProfile $profile, PlayerValidationResult $result, array $hidden): array
    {
        if (! $result->valid) {
            return ['status' => self::STATUS_NOT_FOUND, 'nickname' => null, 'zone' => null, 'stores' => [], 'matched_game_id' => null];
        }

        $zone = null;

        if ($result->server !== null) {
            // A zone game: the stores that sell the UID's zone, as ZoneCheck
            // decides it at checkout. A server we don't sell has no zone.
            $zone = $result->zone;
            $matches = $zone === null
                ? collect()
                : $profile->games()->where('is_active', true)->get()
                    ->filter(fn (Game $game) => in_array($zone, $game->zoneOptions() ?? [], true));
        } else {
            $mapping = $result->countryCode === null ? null : PlayerRegionMapping::query()
                ->where('player_validator_profile_id', $profile->id)
                ->where('country_code', $result->countryCode)
                ->with('game')
                ->first();
            $matches = collect([$mapping?->game])->filter(fn (?Game $game) => $game?->is_active === true);
        }

        if ($matches->isEmpty()) {
            return ['status' => self::STATUS_UNSUPPORTED_REGION, 'nickname' => $result->nickname, 'zone' => null, 'stores' => [], 'matched_game_id' => null];
        }

        $visible = $matches->reject(fn (Game $game) => in_array($game->id, $hidden, true))->values();

        return [
            'status' => $visible->isEmpty() ? self::STATUS_UNAVAILABLE_HERE : self::STATUS_FOUND,
            'nickname' => $result->nickname,
            'zone' => $zone,
            'stores' => $visible->map(fn (Game $game) => ['slug' => $game->slug, 'name' => $game->name])->all(),
            'matched_game_id' => $matches->first()->id,
        ];
    }

    /**
     * @param  list<int>  $hidden
     * @param  Collection<int, Game>|null  $stores
     * @return array{slug: string, name: string, image_url: ?string, player_id_format: string, requires_server_id: bool}|null
     */
    private function family(PlayerValidatorProfile $profile, array $hidden, ?Collection $stores = null): ?array
    {
        if ($profile->public_slug === null || ! $this->registry->hasPublic($profile->key)) {
            return null;
        }

        $stores ??= $this->stores($profile, $hidden);
        if ($stores->isEmpty()) {
            return null;
        }

        $shapes = $stores
            ->map(fn (Game $game) => $game->playerIdFormat().'|'.($game->validation_rules['extra_field'] ?? ''))
            ->unique();

        if ($shapes->count() > 1) {
            Log::warning('Game ID Checker family hidden: its stores take different ID inputs', [
                'profile' => $profile->key,
                'shapes' => $shapes->values()->all(),
            ]);

            return null;
        }

        $reference = $stores->first();

        return [
            'slug' => $profile->public_slug,
            'name' => $profile->public_name ?? $profile->name,
            'image_url' => $profile->public_image_url,
            'player_id_format' => $reference->playerIdFormat(),
            'requires_server_id' => ($reference->validation_rules['extra_field'] ?? null) === 'server_id',
        ];
    }

    /**
     * Active, validator-enabled stores of this family the brand shows.
     *
     * @param  list<int>  $hidden
     * @return Collection<int, Game>
     */
    private function stores(PlayerValidatorProfile $profile, array $hidden): Collection
    {
        return $profile->games()
            ->where('is_active', true)
            ->where('player_validator_enabled', true)
            ->whereNotIn('id', $hidden)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{valid: bool, nickname: ?string, country_code: ?string, provider: string, server: ?string, zone: ?string}  $cached
     */
    private function fromCache(array $cached): PlayerValidationResult
    {
        return $cached['valid']
            ? PlayerValidationResult::valid($cached['provider'], $cached['nickname'], $cached['country_code'], $cached['server'], $cached['zone'])
            : PlayerValidationResult::invalid($cached['provider']);
    }
}
