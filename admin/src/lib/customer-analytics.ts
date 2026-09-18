import { apiFetch, ApiError } from "@/lib/api-client";

/**
 * ANL-1..4. Mirrors backend/app/Services/CustomerAnalytics/CustomerAnalyticsService.php
 * — see that file's own doc comment + ADR-049 for the identity/
 * segmentation rules grilled and pinned 2026-08-28. This client only
 * forwards filters and renders whatever the backend returns.
 */
export type CustomerSegment = "vip" | "frequent" | "dormant" | "new" | "one_time";

export interface CustomerAnalyticsFilters {
  year?: number;
  month?: number;
  affiliateId?: number;
  /** ADR-049 addendum — independently combinable with affiliateId, mirrors Reports' own reseller filter. */
  resellerId?: number;
  segment?: CustomerSegment;
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
  /** ADR-049 addendum — set when this customer_email's orders are a wallet Reseller's, not a retail buyer's. */
  wallet_reseller_id: number | null;
  reseller_name: string | null;
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : "";
}

function filterQuery(filters: CustomerAnalyticsFilters) {
  return buildQuery({
    year: filters.year,
    month: filters.month,
    affiliate_id: filters.affiliateId,
    reseller_id: filters.resellerId,
    segment: filters.segment,
  });
}

export function getCustomerAnalyticsSummary(token: string, filters: CustomerAnalyticsFilters) {
  const query = filterQuery(filters);
  return apiFetch<CustomerAnalyticsSummary>(`/api/customer-analytics/summary${query}`, { token });
}

export function getCustomerAnalyticsCustomers(token: string, filters: CustomerAnalyticsFilters) {
  const query = filterQuery(filters);
  return apiFetch<{ customers: CustomerAnalyticsRow[] }>(`/api/customer-analytics/customers${query}`, { token });
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
  order_history: CustomerOrderHistoryRow[];
}

export function getCustomerDetail(token: string, email: string) {
  return apiFetch<CustomerDetail>(`/api/customer-analytics/customers/${encodeURIComponent(email)}`, { token });
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/** Bearer-token-gated binary download — same pattern as exportReport(). */
export async function exportCustomerAnalytics(token: string, filters: CustomerAnalyticsFilters): Promise<void> {
  const query = filterQuery(filters);

  const response = await fetch(`${API_BASE_URL}/api/customer-analytics/export${query}`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Export failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = "customer-analytics.csv";
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
