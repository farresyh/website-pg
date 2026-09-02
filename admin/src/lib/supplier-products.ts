import { apiFetch } from "@/lib/api-client";
import type { GameValidationRules } from "@/lib/games";

/**
 * MID-1..6/SUPP-3 — see backend/app/Http/Controllers/Middleware/SupplierProductController.php.
 * These are raw, uncurated supplier listings (Stage 1 of Price Sync);
 * `is_promoted` reflects whether a real, customer-facing Package has
 * already been created from this row.
 */
export interface SupplierProduct {
  id: number;
  supplier_id: number;
  external_ref: string;
  name: string;
  category_raw: string | null;
  group_label: string;
  type: string | null;
  price_sen: number | null;
  status_raw: string | null;
  last_synced_at: string;
  is_promoted: boolean;
}

export interface SupplierProductPage {
  data: SupplierProduct[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * One distinct `(supplier, group_label)` group — the level an admin
 * browses first (ADR-067 decision 6). `group_label` is the
 * adapter-set grouping string (Gamevion: its category; Digiflazz: the
 * brand). `game` is set once the whole group has been linked
 * (linkSupplierProductCategory); every item in a linked group shares
 * that Game, so the "which game" decision only happens once per group,
 * not per item.
 */
export interface SupplierProductCategory {
  supplier: { id: number; slug: string; name: string } | null;
  group_label: string;
  total: number;
  promoted_count: number;
  game_id: number | null;
  game: { id: number; name: string; validation_rules?: GameValidationRules | null } | null;
}

export interface LinkCategoryValues {
  supplier_id: number;
  group_label: string;
  game_id?: number;
  new_game?: { name: string; category?: string };
  validation_rules?: GameValidationRules | null;
}

/**
 * No markup field — every promoted Package gets the configured
 * default markup applied automatically; admin adjusts the real
 * per-package markup afterward in /admin/games, not here.
 */
export interface PromoteValues {
  game_id: number;
  name: string;
  /** ADR-034 decision 3/4: optional, admin-curated, never auto-matched by name — the storefront best-price dedup key. Leave unset to promote exactly as before. */
  denomination?: number | null;
}

export function listSupplierProducts(
  token: string,
  params: { search?: string; supplier_id?: number; group_label?: string; page?: number } = {},
) {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.supplier_id !== undefined) query.set("supplier_id", String(params.supplier_id));
  if (params.group_label !== undefined) query.set("group_label", params.group_label);
  if (params.page) query.set("page", String(params.page));
  const qs = query.toString();

  return apiFetch<SupplierProductPage>(`/api/middleware/supplier-products${qs ? `?${qs}` : ""}`, { token });
}

export function listSupplierProductCategories(token: string, params: { search?: string } = {}) {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  const qs = query.toString();

  return apiFetch<SupplierProductCategory[]>(`/api/middleware/supplier-products/categories${qs ? `?${qs}` : ""}`, { token });
}

export function linkSupplierProductCategory(token: string, values: LinkCategoryValues) {
  return apiFetch<{ game: { id: number; name: string } }>("/api/middleware/supplier-products/categories/link", {
    method: "POST",
    token,
    body: values,
  });
}

export function promoteSupplierProduct(token: string, id: number, values: PromoteValues) {
  return apiFetch<{ package: { id: number } }>(`/api/middleware/supplier-products/${id}/promote`, {
    method: "POST",
    token,
    body: values,
  });
}
