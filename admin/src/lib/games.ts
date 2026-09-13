import { apiFetch } from "@/lib/api-client";

/**
 * ADR-005 addendum: Gamevion's order endpoint takes an opaque `data`
 * string with no field schema of its own — we must know per-game
 * whether checkout needs just Player ID (UID), or a second field, and
 * what to call it. Set once per category at /middleware/product-manager's
 * category-link step (LinkCategoryModal), not edited here.
 */
export interface GameValidationRules {
  extra_field?: "server_id" | "zone_id" | null;
}

/** Shared between LinkCategoryModal (set at link time) and the inline editor next to the Product Manager Catalog tab (correct it later). */
export const EXTRA_FIELD_OPTIONS = [
  { value: "", label: "UID only (Player ID)" },
  { value: "server_id", label: "UID + Server ID" },
  { value: "zone_id", label: "UID + Zone ID" },
];

export function extraFieldLabel(extraField: "server_id" | "zone_id" | null | undefined): string {
  switch (extraField) {
    case "server_id":
      return "UID + Server ID";
    case "zone_id":
      return "UID + Zone ID";
    default:
      return "UID only";
  }
}

export interface Game {
  id: number;
  name: string;
  slug: string;
  /** ADR-075's catalog-code addendum (2026-09-04): the game segment of a reseller-facing product code (`{reseller_code}-{denomination-or-catalog_code}`, e.g. `MLMY-14`). Uppercase letters only, unique globally. Null = excluded from the Reseller API/Bot catalog, no effect on the storefront. */
  reseller_code?: string | null;
  category: string | null;
  image_url?: string | null;
  banner_url?: string | null;
  is_active?: boolean;
  /** GAME-6 — display order everywhere (admin list, storefront catalog, Quick Top-Up). Set via reorderGames(), not the edit form. */
  sort_order?: number;
  packages_count?: number;
  validation_rules?: GameValidationRules | null;
  /** MUI-5 follow-up — which admin-created PlayerValidatorProfile (if any) covers this game's storefront "Validate Player ID" flow. */
  player_validator_profile_id?: number | null;
  /** Kill-switch, independent of the profile assignment — hides the storefront button without unassigning the profile. */
  player_validator_enabled?: boolean;
}

export interface GamePackage {
  id: number;
  name: string;
  /** ADR-034: the package's inherent value (e.g. diamond/UC amount) — storefront best-price dedup key is (game_id, denomination). Null for non-integer-amount products (bundles/passes). */
  denomination: number | null;
  /** ADR-075's catalog-code addendum (2026-09-04): the denomination-less equivalent key, for bundles/passes — mutually exclusive with `denomination`. */
  catalog_code: string | null;
  cost_price: number;
  standard_selling_price: number;
  markup_percent: string; // decimal cast serializes as a string
  is_active: boolean;
  supplier_package_ref: string;
  /** Read-only — has the supplier turned this item off on their own side? */
  supplier_active: boolean;
}

export interface UpdateGameValues {
  name: string;
  slug: string;
  reseller_code?: string | null;
  category?: string | null;
  image_url?: string | null;
  banner_url?: string | null;
  is_active: boolean;
  player_validator_profile_id?: number | null;
  player_validator_enabled?: boolean;
}

export interface UpdatePackageValues {
  name: string;
}

export function listGames(token: string, params: { search?: string; status?: "active" | "inactive" } = {}) {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.status) query.set("status", params.status);
  const qs = query.toString();

  return apiFetch<Game[]>(`/api/games${qs ? `?${qs}` : ""}`, { token });
}

export function listGamePackages(token: string, gameId: number) {
  return apiFetch<GamePackage[]>(`/api/games/${gameId}/packages`, { token });
}

export function updateGame(token: string, gameId: number, values: UpdateGameValues) {
  return apiFetch<Game>(`/api/games/${gameId}`, { method: "PUT", token, body: values });
}

export function deleteGame(token: string, gameId: number) {
  return apiFetch<void>(`/api/games/${gameId}`, { method: "DELETE", token });
}

/** GAME-6 — `gameIds` is the complete new front-to-back order; `sort_order` is written as each id's position. */
export function reorderGames(token: string, gameIds: number[]) {
  return apiFetch<{ games_reordered: number }>(`/api/games/reorder`, {
    method: "POST",
    token,
    body: { game_ids: gameIds },
  });
}

export function updatePackage(token: string, packageId: number, values: UpdatePackageValues) {
  return apiFetch<GamePackage>(`/api/packages/${packageId}`, { method: "PUT", token, body: values });
}

export function updatePackageMarkup(token: string, packageId: number, markupPercent: number) {
  return apiFetch<GamePackage>(`/api/packages/${packageId}/markup`, {
    method: "PATCH",
    token,
    body: { markup_percent: markupPercent },
  });
}

export function updatePackageStatus(token: string, packageId: number, isActive: boolean) {
  return apiFetch<GamePackage>(`/api/packages/${packageId}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

/** ADR-034 decision 3: the edit-time half of curating denomination — the promote-time half is PromoteValues/promoteSupplierProduct. Pass null to clear it. */
export function updatePackageDenomination(token: string, packageId: number, denomination: number | null) {
  return apiFetch<GamePackage>(`/api/packages/${packageId}/denomination`, {
    method: "PATCH",
    token,
    body: { denomination },
  });
}

/** ADR-075's catalog-code addendum (2026-09-04): the catalog_code counterpart to updatePackageDenomination() — for bundle/pass packages. Pass null to clear it. */
export function updatePackageCatalogCode(token: string, packageId: number, catalogCode: string | null) {
  return apiFetch<GamePackage>(`/api/packages/${packageId}/catalog-code`, {
    method: "PATCH",
    token,
    body: { catalog_code: catalogCode },
  });
}

export function deletePackage(token: string, packageId: number) {
  return apiFetch<void>(`/api/packages/${packageId}`, { method: "DELETE", token });
}
