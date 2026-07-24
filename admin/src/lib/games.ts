import { apiFetch } from "@/lib/api-client";

export interface Game {
  id: number;
  name: string;
  slug: string;
  category: string | null;
  image_url?: string | null;
  banner_url?: string | null;
  is_active?: boolean;
  packages_count?: number;
}

export interface GamePackage {
  id: number;
  name: string;
  cost_price: number;
  reseller_cost_price: number;
  markup_percent: string; // decimal cast serializes as a string
  is_active: boolean;
  supplier_package_ref: string;
  /** Read-only — has the supplier turned this item off on their own side? */
  supplier_active: boolean;
}

export interface UpdateGameValues {
  name: string;
  slug: string;
  category?: string | null;
  image_url?: string | null;
  banner_url?: string | null;
  is_active: boolean;
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

export function deletePackage(token: string, packageId: number) {
  return apiFetch<void>(`/api/packages/${packageId}`, { method: "DELETE", token });
}
