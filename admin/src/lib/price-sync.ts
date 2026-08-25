import { apiFetch } from "@/lib/api-client";

/**
 * ADR-015: mirrors backend/app/Models/PriceSyncRun.php — one row per
 * SyncSupplierPricesJob run, polled by /middleware/price-sync while
 * `queued`/`running` so the admin panel never blocks on the live
 * Gamevion call itself.
 */
export interface PriceSyncRun {
  id: number;
  status: "queued" | "running" | "success" | "failed";
  triggered_by: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  stats: {
    catalog_total: number;
    catalog_created: number;
    catalog_updated: number;
    price_changed: number;
    deactivated: number;
    // ADR-025 decision #1: absent on any run recorded before this
    // shipped — older Sync History rows simply show nothing for these.
    floor_rejected?: number;
    price_anomalies?: number;
    // ADR-033 addendum decision 3: absent on any run recorded before
    // this shipped, same convention as floor_rejected/price_anomalies
    // above — empty (not absent) on any run that touched only MYR
    // suppliers.
    fx_rates_used?: Array<{ from: string; to: string; rate: number; source: string }>;
  } | null;
  error_message: string | null;
}

export interface PriceSyncRunPage {
  data: PriceSyncRun[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * ADR-016 decision #1: the five Price Sync Center stat cards.
 */
export interface PriceSyncStats {
  total_games: number;
  active_packages: number;
  pending_reactivation_count: number;
  pending_price_change_count: number;
  last_sync_status: PriceSyncRun["status"] | null;
  last_sync_at: string | null;
}

/**
 * ADR-016 decision #1/#2: Sync Details modal — per-game grouped
 * price/cost diffs and deactivations for one run. Deactivations can't
 * be broken down by game for runs from before DeactivationLog existed
 * — those simply render an empty list, not an error.
 */
export interface SyncDetailsGame {
  game: { id: number; name: string } | null;
  price_changes: Array<{
    package: { id: number; name: string };
    old_cost_price: number;
    new_cost_price: number;
    old_reseller_cost_price: number;
    new_reseller_cost_price: number;
  }>;
  deactivated_packages: Array<{ id: number; name: string }>;
}

export interface SyncDetails {
  run: PriceSyncRun;
  games_touched: number;
  games: SyncDetailsGame[];
}

/**
 * ADR-015 decision #3: a package Deactivation Detection turned off
 * whose supplier item is active again — computed live by the backend,
 * never auto-reactivated.
 */
export interface PendingReactivation {
  id: number;
  name: string;
  cost_price: number;
  reseller_cost_price: number;
  deactivated_reason: "supplier_sync";
  deactivated_at: string;
  supplier_package_ref: string;
  game: { id: number; name: string } | null;
  supplier: { id: number; name: string } | null;
}

/**
 * ADR-016 decision #3: "Manually Dismissed Packages" — a package an
 * admin took out of Pending Reactivation monitoring
 * (`deactivated_reason === 'admin'`), restorable regardless of
 * current live supplier status.
 */
export interface DismissedPackage {
  id: number;
  name: string;
  cost_price: number;
  reseller_cost_price: number;
  deactivated_reason: "admin";
  deactivated_at: string;
  supplier_package_ref: string;
  game: { id: number; name: string } | null;
  supplier: { id: number; name: string } | null;
}

export interface DismissedPackagePage {
  data: DismissedPackage[];
  current_page: number;
  last_page: number;
  total: number;
}

export function triggerPriceSync(token: string) {
  return apiFetch<PriceSyncRun>("/api/middleware/price-sync", { method: "POST", token });
}

export function getPriceSyncRun(token: string, runId: number) {
  return apiFetch<PriceSyncRun>(`/api/middleware/price-sync/runs/${runId}`, { token });
}

export function getPriceSyncStats(token: string) {
  return apiFetch<PriceSyncStats>("/api/middleware/price-sync/stats", { token });
}

export function listPriceSyncRuns(token: string, page = 1) {
  const query = page > 1 ? `?page=${page}` : "";

  return apiFetch<PriceSyncRunPage>(`/api/middleware/price-sync/runs${query}`, { token });
}

export function getPriceSyncRunDetails(token: string, runId: number) {
  return apiFetch<SyncDetails>(`/api/middleware/price-sync/runs/${runId}/details`, { token });
}

export function listDismissedPackages(token: string, page = 1) {
  const query = page > 1 ? `?page=${page}` : "";

  return apiFetch<DismissedPackagePage>(`/api/middleware/price-sync/dismissed-packages${query}`, { token });
}

export function restoreDismissedPackage(token: string, packageId: number) {
  return apiFetch<DismissedPackage>(`/api/middleware/price-sync/dismissed-packages/${packageId}/restore`, {
    method: "PATCH",
    token,
  });
}

export function listPendingReactivations(token: string) {
  return apiFetch<PendingReactivation[]>("/api/middleware/price-sync/pending-reactivations", { token });
}

export function approvePendingReactivation(token: string, packageId: number) {
  return apiFetch<PendingReactivation>(`/api/middleware/price-sync/pending-reactivations/${packageId}/approve`, {
    method: "PATCH",
    token,
  });
}

export function dismissPendingReactivation(token: string, packageId: number) {
  return apiFetch<PendingReactivation>(`/api/middleware/price-sync/pending-reactivations/${packageId}/dismiss`, {
    method: "PATCH",
    token,
  });
}

export function bulkApprovePendingReactivations(token: string, packageIds: number[]) {
  return apiFetch<{ processed: number }>("/api/middleware/price-sync/pending-reactivations/bulk-approve", {
    method: "POST",
    token,
    body: { package_ids: packageIds },
  });
}

export function bulkDismissPendingReactivations(token: string, packageIds: number[]) {
  return apiFetch<{ processed: number }>("/api/middleware/price-sync/pending-reactivations/bulk-dismiss", {
    method: "POST",
    token,
    body: { package_ids: packageIds },
  });
}

/**
 * ADR-025 decision #2/#8: a supplier price swing large enough to
 * cross the configured threshold, blocked from being applied until an
 * admin reviews it here.
 */
export interface PendingPriceChange {
  id: number;
  old_cost_price: number;
  proposed_cost_price: number;
  old_reseller_cost_price: number;
  proposed_reseller_cost_price: number;
  status: "pending" | "approved" | "dismissed";
  created_at: string;
  package: {
    id: number;
    name: string;
    supplier_package_ref: string;
    game: { id: number; name: string } | null;
    supplier: { id: number; name: string } | null;
  };
}

export function listPendingPriceChanges(token: string) {
  return apiFetch<PendingPriceChange[]>("/api/middleware/price-sync/pending-price-changes", { token });
}

export function approvePendingPriceChange(token: string, id: number) {
  return apiFetch<PendingPriceChange>(`/api/middleware/price-sync/pending-price-changes/${id}/approve`, {
    method: "PATCH",
    token,
  });
}

export function dismissPendingPriceChange(token: string, id: number) {
  return apiFetch<PendingPriceChange>(`/api/middleware/price-sync/pending-price-changes/${id}/dismiss`, {
    method: "PATCH",
    token,
  });
}

/**
 * ADR-033 addendum decision 1/2: one row per FX API fetch, generic
 * across any currency pair — the "FX Rate History" section's data.
 */
export interface CurrencyRate {
  id: number;
  from: string;
  to: string;
  rate: number;
  source: string;
  fetched_at: string;
}

export interface CurrencyRatePage {
  data: CurrencyRate[];
  current_page: number;
  last_page: number;
  total: number;
}

export function listCurrencyRates(token: string, page = 1) {
  const query = page > 1 ? `?page=${page}` : "";

  return apiFetch<CurrencyRatePage>(`/api/middleware/price-sync/fx-rates${query}`, { token });
}
