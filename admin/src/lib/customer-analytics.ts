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
