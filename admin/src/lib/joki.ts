import { apiFetch } from "@/lib/api-client";

/**
 * ADR-126 decisions 10, 16, 23-24: Admin → Joki. Board filters and counts
 * come from the backend's JokiBoard, the one definition the Dashboard card
 * shares. Prices are whole ringgit (`*_rm`); payouts are sen.
 */
export type JokiFilter = "waiting" | "in_progress" | "stale" | "needs_voucher" | "done" | "failed";

export interface JokiCounts {
  waiting: number;
  in_progress: number;
  stale: number;
  needs_voucher: number;
}

export interface JokiBoardRow {
  id: number;
  order_number: string;
  delivery_status: string;
  paid_at: string | null;
  supplier_id: number;
  penjoki: string | null;
  from_rank: string | null;
  to_rank: string | null;
  current_rank: string | null;
  is_express: boolean;
  role: string | null;
  hero_request: string | null;
  play_window: string | null;
  selling_price: number;
  payout_sen: number;
  last_activity_at: string | null;
  sla_due_at: string | null;
  is_stale: boolean;
}

export interface JokiBoardPage {
  data: JokiBoardRow[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface Penjoki {
  id: number;
  name: string;
  is_active: boolean;
  has_group: boolean;
  open_orders: number;
  owed_sen: number;
}

export interface JokiTier {
  id: number;
  name: string;
  sort_order: number;
  kind: "divisional" | "mythic";
  divisions: number | null;
  stars_per_division: number | null;
  mythic_from_stars: number | null;
  rate_rm: number;
  express_rate_rm: number;
  icon_url: string | null;
}

export interface JokiRank {
  tier_id: number;
  tier_name?: string;
  division: number | null;
  stars: number | null;
  label?: string;
}

export interface JokiRouteRow {
  id: number;
  package_id: number;
  name: string;
  is_active: boolean;
  from: JokiRank;
  to: JokiRank;
  price_rm: number | null;
  is_quotation: boolean;
}

export interface JokiSettings {
  is_enabled: boolean;
  payout_share_pct: number;
  min_stars: number;
  grading_price_rm: number;
  grading_express_price_rm: number;
  grading_lands_at_stars: number;
  max_mythic_stars: number;
  roles: string[];
  express_description: string | null;
  terms_html: string | null;
  progress_message_interval_minutes: number;
}

export interface JokiSettingsResponse {
  settings: JokiSettings;
  is_set_up: boolean;
  tiers: JokiTier[];
  routes: JokiRouteRow[];
}

/** The Order Detail Joki card (`joki` on the order detail response). */
export interface JokiOrderDetail {
  from_rank: JokiRank | null;
  to_rank: JokiRank | null;
  current_rank: JokiRank | null;
  current_rank_at: string | null;
  is_express: boolean;
  role: string | null;
  hero_request: string | null;
  play_window: string | null;
  payout_share_pct: number | null;
  is_with_penjoki: boolean;
  assignments: { from: string | null; to: string | null; by: string; at: string | null }[];
  progress: { rank: string | null; by: string; channel: string; at: string | null }[];
  tiers: Pick<JokiTier, "id" | "name" | "kind" | "divisions" | "stars_per_division" | "mythic_from_stars">[];
}

export interface JokiBoardFilters {
  filter: JokiFilter | "all";
  page: number;
  search?: string;
  supplierId?: number;
  dateFrom?: string;
  dateTo?: string;
}

/** The board plus the same search / date bounds as /orders, and a penjoki. */
export function listJokiOrders({ filter, page, search, supplierId, dateFrom, dateTo }: JokiBoardFilters) {
  const query = new URLSearchParams({ page: String(page) });
  if (filter !== "all") query.set("filter", filter);
  if (search) query.set("search", search);
  if (supplierId) query.set("supplier_id", String(supplierId));
  if (dateFrom) query.set("date_from", dateFrom);
  if (dateTo) query.set("date_to", dateTo);

  return apiFetch<JokiBoardPage>(`/api/joki-admin/orders?${query}`);
}

export function getJokiCounts() {
  return apiFetch<JokiCounts & { all: number }>("/api/joki-admin/summary");
}

export function listPenjoki() {
  return apiFetch<Penjoki[]>("/api/joki-admin/penjoki");
}

export function getJokiSettings() {
  return apiFetch<JokiSettingsResponse>("/api/joki-admin/settings");
}

export function updateJokiSettings(values: Partial<JokiSettings>) {
  return apiFetch<JokiSettings>("/api/joki-admin/settings", { method: "PUT", body: values });
}

/** A tier sends only the shape fields of its own kind (divisions + stars, or the Mythic start). */
export function updateJokiTiers(tiers: (Pick<JokiTier, "id" | "name" | "rate_rm" | "express_rate_rm" | "icon_url"> & Partial<Pick<JokiTier, "divisions" | "stars_per_division" | "mythic_from_stars">>)[]) {
  return apiFetch<JokiTier[]>("/api/joki-admin/tiers", { method: "PUT", body: { tiers } });
}

export interface SaveJokiRouteValues {
  name?: string;
  from?: JokiRank;
  to?: JokiRank;
  price_rm?: number | null;
  is_quotation?: boolean;
  is_active?: boolean;
}

export function createJokiRoute(values: SaveJokiRouteValues) {
  return apiFetch<{ id: number }>("/api/joki-admin/routes", { method: "POST", body: values });
}

export function updateJokiRoute(id: number, values: SaveJokiRouteValues) {
  return apiFetch<{ id: number }>(`/api/joki-admin/routes/${id}`, { method: "PUT", body: values });
}

/** ADR-126 decision 16: Pending only, an active penjoki with a group; the backend checks under the lock. */
export function assignJokiOrder(orderId: number, supplierId: number) {
  return apiFetch<{ order_number: string; supplier_id: number }>(`/api/orders/${orderId}/joki/assign`, { method: "POST", body: { supplier_id: supplierId } });
}

/** ADR-126 decision 10: a rank the penjoki reported another way; checked against the rank model. */
export function updateJokiProgress(orderId: number, rank: { tier_id: number; division: number | null; stars: number }) {
  return apiFetch<{ current_rank: JokiRank }>(`/api/orders/${orderId}/joki/progress`, { method: "POST", body: rank });
}

export const JOKI_FILTER_LABELS: Record<JokiFilter, string> = {
  waiting: "Waiting for a penjoki",
  in_progress: "In progress",
  stale: "Stale",
  needs_voucher: "Needs voucher",
  done: "Done",
  failed: "Failed",
};

export const rm = (sen: number) => `RM ${(sen / 100).toFixed(2)}`;
