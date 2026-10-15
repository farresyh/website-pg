import { apiFetch, apiDownload } from "@/lib/api-client";

/**
 * ANL-1..4. Mirrors backend/app/Services/CustomerAnalytics/CustomerAnalyticsService.php
 * — see that file's own doc comment + ADR-049 for the identity/
 * segmentation rules grilled and pinned 2026-08-28. This client only
 * forwards filters and renders whatever the backend returns.
 */
export type CustomerSegment = "vip" | "frequent" | "dormant" | "new" | "one_time";

export type CustomerSort = "total_spent" | "orders_count" | "last_order_at";

/** ADR-049 2026-10-15 addendum. `from`/`to` are Malaysia dates (YYYY-MM-DD), as on Reports. */
export interface CustomerAnalyticsFilters {
  from?: string;
  to?: string;
  affiliateId?: number;
  /** ADR-049 addendum — independently combinable with affiliateId, mirrors Reports' own reseller filter. */
  resellerId?: number;
}

/** The list-only filters: they pick which customers show, never the stat cards. */
export interface CustomerListQuery extends CustomerAnalyticsFilters {
  segment?: CustomerSegment;
  search?: string;
  sort?: CustomerSort;
  page?: number;
  perPage?: number;
}

/** Laravel's paginator shape, as on Orders. */
export interface Paged<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CustomerAnalyticsSummary {
  total_customers: number;
  avg_order_value: number;
  repeat_rate_pct: number;
  top_spender: {
    customer_email: string;
    customer_name: string | null;
    total_spent: number;
  } | null;
}

export interface CustomerAnalyticsRow {
  customer_email: string;
  customer_name: string | null;
  segment: CustomerSegment | null;
  segment_label: string;
  orders_count: number;
  total_spent: number;
  last_order_at: string;
  /** "Retail", "Reseller: A" or "Reseller: A + Retail" — every order the customer ever paid for. */
  source_label: string;
  has_reseller_orders: boolean;
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : "";
}

function filterQuery(filters: CustomerListQuery) {
  return buildQuery({
    from: filters.from,
    to: filters.to,
    affiliate_id: filters.affiliateId,
    reseller_id: filters.resellerId,
    segment: filters.segment,
    search: filters.search,
    sort: filters.sort,
    page: filters.page,
    per_page: filters.perPage,
  });
}

export function getCustomerAnalyticsSummary(filters: CustomerAnalyticsFilters, signal?: AbortSignal) {
  const { from, to, affiliateId, resellerId } = filters;
  return apiFetch<CustomerAnalyticsSummary>(`/api/customer-analytics/summary${filterQuery({ from, to, affiliateId, resellerId })}`, { signal });
}

export function getCustomerAnalyticsCustomers(query: CustomerListQuery, signal?: AbortSignal) {
  return apiFetch<Paged<CustomerAnalyticsRow>>(`/api/customer-analytics/customers${filterQuery(query)}`, { signal });
}

/** ANL-5 (ADR-050) — full drill-down for one customer, always their full lifetime across every affiliate. */
export interface CustomerDetailStats {
  total_orders: number;
  total_spent: number;
  avg_order_value: number;
  customer_since: string;
}

/** ADR-050 decision 2 — scoped to delivered orders only, a narrower population than the stats above. */
export interface CustomerProfitAnalysis {
  total_revenue: number;
  supplier_cost: number;
  affiliate_commission: number;
  transaction_fees: number;
  system_profit: number;
}

export interface CustomerMonthlyTrendPoint {
  month: string;
  label: string;
  total_spent: number;
}

export interface CustomerTopBreakdownRow {
  id: number | null;
  name: string;
  orders_count: number;
  total_spent: number;
  pct_of_spend: number;
}

export interface CustomerOrderHistoryRow {
  id: number;
  order_number: string;
  paid_at: string;
  package_name: string;
  /** ADR-050 addendum — "Reseller: {name}" for a wallet order, the affiliate's business_name otherwise. */
  source_name: string;
  final_amount: number;
  affiliate_profit: number | null;
  system_profit: number | null;
  delivery_status: string;
}

export interface CustomerDetail {
  customer_email: string;
  customer_name: string | null;
  customer_phone: string | null;
  segment: CustomerSegment | null;
  segment_label: string;
  stats: CustomerDetailStats;
  profit_analysis: CustomerProfitAnalysis;
  monthly_trend: CustomerMonthlyTrendPoint[];
  top_packages: CustomerTopBreakdownRow[];
  /** ADR-050 addendum — was top_affiliates; renamed since a wallet Reseller order's affiliate is always the primary brand. */
  top_sources: CustomerTopBreakdownRow[];
  /** ADR-050 2026-10-15 addendum — paged, 25 per page. */
  order_history: Paged<CustomerOrderHistoryRow>;
}

export function getCustomerDetail(email: string, historyPage = 1, signal?: AbortSignal) {
  const query = historyPage > 1 ? `?history_page=${historyPage}` : "";
  return apiFetch<CustomerDetail>(`/api/customer-analytics/customers/${encodeURIComponent(email)}${query}`, { signal });
}


/** Auth-gated binary download — same pattern as exportReport(). */
export async function exportCustomerAnalytics(filters: Omit<CustomerListQuery, "page" | "perPage">): Promise<void> {
  const query = filterQuery(filters);

  await apiDownload(`/api/customer-analytics/export${query}`, "customer-analytics.csv");
}
