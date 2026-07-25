<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\StorePlayerRegionMappingRequest;
use App\Http\Requests\Middleware\UpdatePlayerRegionMappingRequest;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidatorProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * MUI-5 — "region-based game routing rules", nested under the
 * validator profile they belong to (PlayerValidatorProfileController)
 * rather than a flat, loosely-tagged list — the founder's own
 * correction, 2026-07-25. update()/destroy() re-check that `{mapping}`
 * actually belongs to `{validator}` — Laravel's implicit route model
 * binding resolves `$mapping` purely by its own id and would
 * otherwise silently ignore a mismatched `{validator}` segment.
 */
class PlayerRegionMappingController extends Controller
{
    public function store(StorePlayerRegionMappingRequest $request, PlayerValidatorProfile $validator): JsonResponse
    {
        $mapping = $validator->mappings()->create($request->validated());

        return response()->json($mapping->load('game:id,name,slug'), 201);
    }

    public function update(
        UpdatePlayerRegionMappingRequest $request,
        PlayerValidatorProfile $validator,
        PlayerRegionMapping $mapping,
    ): JsonResponse {
        $this->assertBelongsToValidator($validator, $mapping);

        $mapping->update($request->validated());

        return response()->json($mapping->load('game:id,name,slug'));
    }

    public function destroy(PlayerValidatorProfile $validator, PlayerRegionMapping $mapping): JsonResponse
    {
        $this->assertBelongsToValidator($validator, $mapping);

        $mapping->delete();

        return response()->json(null, 204);
    }

    private function assertBelongsToValidator(PlayerValidatorProfile $validator, PlayerRegionMapping $mapping): void
    {
        if ($mapping->player_validator_profile_id !== $validator->id) {
            throw new ModelNotFoundException("Mapping {$mapping->id} does not belong to validator {$validator->id}");
        }
    }
}
