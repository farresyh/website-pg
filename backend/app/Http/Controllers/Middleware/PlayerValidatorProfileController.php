<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\StorePlayerValidatorProfileRequest;
use App\Http\Requests\Middleware\TestPlayerValidatorProfileRequest;
use App\Http\Requests\Middleware\UpdatePlayerValidatorProfileRequest;
use App\Models\PlayerValidatorProfile;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use Illuminate\Http\JsonResponse;

/**
 * MUI-5 — "Validators": admin-created profiles, each pointing at a
 * real backend implementation via `key` (constrained to
 * PlayerValidatorRegistry::AVAILABLE_KEYS). Region mappings
 * (PlayerRegionMappingController) live nested under a profile, not as
 * a flat, loosely-tagged list — the founder's own correction,
 * 2026-07-25, so it's visible at a glance which mappings belong to
 * which validator.
 */
class PlayerValidatorProfileController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            PlayerValidatorProfile::query()
                ->with('mappings.game:id,name,slug')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * The finite set of keys with a real bound implementation —
     * powers the "Key" select in "Create Validator" so a profile can
     * never be created pointing at nothing.
     */
    public function availableKeys(): JsonResponse
    {
        return response()->json(
            collect(PlayerValidatorRegistry::AVAILABLE_KEYS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        );
    }

    public function store(StorePlayerValidatorProfileRequest $request): JsonResponse
    {
        $profile = PlayerValidatorProfile::create($request->validated());

        return response()->json($profile->load('mappings.game:id,name,slug'), 201);
    }

    public function update(UpdatePlayerValidatorProfileRequest $request, PlayerValidatorProfile $validator): JsonResponse
    {
        $validator->update($request->validated());

        return response()->json($validator->load('mappings.game:id,name,slug'));
    }

    /**
     * Cascades to every PlayerRegionMapping under this profile
     * (`player_region_mappings.player_validator_profile_id` is
     * `cascadeOnDelete()`); any Game that had this profile assigned
     * has it cleared, not broken (`games.player_validator_profile_id`
     * is `nullOnDelete()`).
     */
    public function destroy(PlayerValidatorProfile $validator): JsonResponse
    {
        $validator->delete();

        return response()->json(null, 204);
    }

    /**
     * Fires a real validate() call against whatever provider chain
     * `key` resolves to, using an admin-supplied test account — this
     * is the direct answer to "is this validator actually plugged in
     * on the backend": an UnsupportedPlayerValidatorException means
     * `key` has no real binding at all, a ProviderUnavailableException
     * means it's bound but every real provider failed this specific
     * call, and a normal result proves the whole chain genuinely
     * works. Records the outcome the same way PaymentMethodController::test()
     * does, and returns the full raw result so the admin can see
     * exactly what came back — not just a pass/fail summary.
     *
     * Always responds 200 (`success` carries the outcome) — this is a
     * diagnostic action reporting on a third-party system's state, not
     * a failed request of our own, same reasoning as
     * PaymentMethodController::test() never itself returning an error
     * status even when the channel test fails.
     */
    public function test(TestPlayerValidatorProfileRequest $request, PlayerValidatorProfile $validator, PlayerValidatorRegistry $registry): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $registry->resolve($validator->key)->validate($data['player_id'], $data['server_id'] ?? null);

            $validator->update([
                'last_tested_at' => now(),
                'last_test_result' => $result->valid
                    ? "valid — nickname: {$result->nickname}, country: {$result->countryCode}, via {$result->provider}"
                    : "invalid ID (confirmed by {$result->provider})",
            ]);

            return response()->json([
                'success' => true,
                'validator' => $validator->fresh('mappings.game:id,name,slug'),
                'result' => [
                    'valid' => $result->valid,
                    'nickname' => $result->nickname,
                    'country_code' => $result->countryCode,
                    'provider' => $result->provider,
                ],
            ]);
        } catch (UnsupportedPlayerValidatorException $e) {
            $validator->update([
                'last_tested_at' => now(),
                'last_test_result' => "not plugged in: {$e->getMessage()}",
            ]);

            return response()->json([
                'success' => false,
                'validator' => $validator->fresh('mappings.game:id,name,slug'),
                'error' => $e->getMessage(),
            ]);
        } catch (ProviderUnavailableException $e) {
            $validator->update([
                'last_tested_at' => now(),
                'last_test_result' => "all providers unavailable: {$e->getMessage()}",
            ]);

            return response()->json([
                'success' => false,
                'validator' => $validator->fresh('mappings.game:id,name,slug'),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
