import { apiFetch } from "@/lib/api-client";

/**
 * MUI-5 — "Validators": admin-created profiles, each pointing at a
 * real backend implementation via `key` (constrained server-side to
 * PlayerValidatorRegistry::AVAILABLE_KEYS). Region mappings live
 * nested under the profile they belong to, not a flat, loosely-tagged
 * list — see backend/app/Http/Controllers/Middleware/PlayerValidatorProfileController.php.
 */
export interface PlayerRegionMapping {
  id: number;
  player_validator_profile_id: number;
  country_code: string;
  country_name: string;
  game_id: number;
  game: {
    id: number;
    name: string;
    slug: string;
  };
}

export interface PlayerValidatorProfile {
  id: number;
  name: string;
  key: string;
  last_tested_at: string | null;
  last_test_result: string | null;
  mappings: PlayerRegionMapping[];
}

export interface AvailableValidatorKey {
  key: string;
  label: string;
}

export interface TestValidatorResult {
  success: boolean;
  validator: PlayerValidatorProfile;
  result?: {
    valid: boolean;
    nickname: string | null;
    country_code: string | null;
    provider: string;
  };
  error?: string;
}

export function listPlayerValidatorProfiles(token: string) {
  return apiFetch<PlayerValidatorProfile[]>("/api/middleware/validators", { token });
}

export function listAvailableValidatorKeys(token: string) {
  return apiFetch<AvailableValidatorKey[]>("/api/middleware/validators/available-keys", { token });
}

export function createPlayerValidatorProfile(token: string, values: { name: string; key: string }) {
  return apiFetch<PlayerValidatorProfile>("/api/middleware/validators", {
    method: "POST",
    token,
    body: values,
  });
}

export function updatePlayerValidatorProfile(token: string, id: number, values: { name: string }) {
  return apiFetch<PlayerValidatorProfile>(`/api/middleware/validators/${id}`, {
    method: "PUT",
    token,
    body: values,
  });
}

export function deletePlayerValidatorProfile(token: string, id: number) {
  return apiFetch<void>(`/api/middleware/validators/${id}`, { method: "DELETE", token });
}

export function testPlayerValidatorProfile(
  token: string,
  id: number,
  values: { player_id: string; server_id?: string },
) {
  return apiFetch<TestValidatorResult>(`/api/middleware/validators/${id}/test`, {
    method: "POST",
    token,
    body: values,
  });
}

export function createPlayerRegionMapping(
  token: string,
  validatorId: number,
  values: { country_code: string; country_name: string; game_id: number },
) {
  return apiFetch<PlayerRegionMapping>(`/api/middleware/validators/${validatorId}/mappings`, {
    method: "POST",
    token,
    body: values,
  });
}

export function updatePlayerRegionMapping(
  token: string,
  validatorId: number,
  mappingId: number,
  values: { country_name: string; game_id: number },
) {
  return apiFetch<PlayerRegionMapping>(`/api/middleware/validators/${validatorId}/mappings/${mappingId}`, {
    method: "PUT",
    token,
    body: values,
  });
}

export function deletePlayerRegionMapping(token: string, validatorId: number, mappingId: number) {
  return apiFetch<void>(`/api/middleware/validators/${validatorId}/mappings/${mappingId}`, {
    method: "DELETE",
    token,
  });
}
