<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerValidation\ValidatePlayerRequest;
use App\Models\Game;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Public, guest-callable "Validate Player ID" endpoint (ADR-011, same
 * no-auth reasoning as CheckoutController) — the backend half of the
 * Player-ID Validation follow-up (docs/prd.md §14's dedicated NEXT
 * SESSION note). Storefront UI isn't wired to this yet (no Storefront
 * app exists); this closes the API contract it will call.
 *
 * Resolves `game.player_validator_profile_id` via PlayerValidatorRegistry,
 * looks up the returned country in `player_region_mappings` scoped to
 * that profile, and returns one of four states the storefront reacts
 * to: `invalid` / `region_unknown` / `wrong_region` (+ `redirect_game`
 * for the "Go to X Store" CTA) / `valid`. Writes a `player_validations`
 * row on every attempt that actually reaches a provider — the first
 * thing that ever writes to that table.
 */
class PlayerValidationController extends Controller
{
    public function __construct(private readonly PlayerValidatorRegistry $registry) {}

    public function store(ValidatePlayerRequest $request, Game $game): JsonResponse
    {
        $data = $request->validated();

        if (! $game->player_validator_enabled || $game->player_validator_profile_id === null) {
            throw ValidationException::withMessages([
                'player_id' => ['Player ID validation is not available for this game.'],
            ]);
        }

        $profile = $game->playerValidatorProfile()->firstOrFail();

        try {
            $result = $this->registry->resolve($profile->key)->validate($data['player_id'], $data['server_id'] ?? null);
        } catch (UnsupportedPlayerValidatorException|ProviderUnavailableException $e) {
            return $this->respond($this->record($game, $data, 'region_unknown'));
        }

        return $this->respond($this->record($game, $data, ...$this->resolveState($game, $profile->id, $result)));
    }

    /**
     * @return array{0: string, 1: PlayerValidationResult, 2: ?PlayerRegionMapping}
     */
    private function resolveState(Game $game, int $profileId, PlayerValidationResult $result): array
    {
        if (! $result->valid) {
            return ['invalid', $result, null];
        }

        if ($result->countryCode === null) {
            return ['region_unknown', $result, null];
        }

        $mapping = PlayerRegionMapping::query()
            ->where('player_validator_profile_id', $profileId)
            ->where('country_code', $result->countryCode)
            ->with('game:id,name,slug')
            ->first();

        if ($mapping === null) {
            return ['region_unknown', $result, null];
        }

        if ($mapping->game_id === $game->id) {
            return ['valid', $result, null];
        }

        return ['wrong_region', $result, $mapping];
    }

    /**
     * @param  array{player_id: string, server_id?: string|null}  $data
     */
    private function record(
        Game $game,
        array $data,
        string $status,
        ?PlayerValidationResult $result = null,
        ?PlayerRegionMapping $redirectMapping = null,
    ): array {
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => $data['player_id'],
            'server_id' => $data['server_id'] ?? null,
            'status' => $status,
            'country_code' => $result?->countryCode,
            'nickname' => $result?->nickname,
            'provider' => $result?->provider,
            'validated_at' => now(),
        ]);

        return [
            'status' => $status,
            'nickname' => $result?->nickname,
            'country_code' => $result?->countryCode,
            'redirect_game' => $redirectMapping !== null
                ? ['slug' => $redirectMapping->game->slug, 'name' => $redirectMapping->game->name]
                : null,
        ];
    }

    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload);
    }
}
