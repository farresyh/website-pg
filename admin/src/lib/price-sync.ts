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
  stats: {
    catalog_total: number;
    catalog_created: number;
    catalog_updated: number;
    price_changed: number;
    deactivated: number;
  } | null;
  error_message: string | null;
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

export function triggerPriceSync(token: string) {
  return apiFetch<PriceSyncRun>("/api/middleware/price-sync", { method: "POST", token });
}

export function getPriceSyncRun(token: string, runId: number) {
  return apiFetch<PriceSyncRun>(`/api/middleware/price-sync/runs/${runId}`, { token });
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
