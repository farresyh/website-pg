import { apiFetch, ApiError } from "@/lib/api-client";

/**
 * RPT-1..3. Mirrors backend/app/Services/Report/ReportService.php — see
 * that file's own doc comment for the sales/profit definitions grilled
 * and pinned 2026-08-26 (ledger-sourced profit, paid_at-scoped sales,
 * Asia/Kuala_Lumpur day-bucketing). This client only forwards filters
 * and renders whatever the backend returns — no calculation here.
 */
export interface ReportFilters {
  year?: number;
  month?: number;
  resellerId?: number;
}

export interface ReportSummary {
  total_sales: number;
  orders_count: number;
  platform_profit: number;
  reseller_profit: number;
  margin_pct: number;
  avg_order_value: number;
  latest_order: {
    order_number: string;
    paid_at: string;
    customer_email: string;
    final_amount: number;
  } | null;
}

export interface ReportTrendDay {
  date: string;
  sales: number;
  platform_profit: number;
  reseller_profit: number;
}

export interface ReportDailyBreakdownRow {
  date: string;
  orders_count: number;
  sales: number;
  platform_profit: number;
  reseller_profit: number;
  transaction_fees: number;
  avg_order_value: number;
}

export interface ReportGameRow {
  game_id: number | null;
  game_name: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
  reseller_profit: number;
  avg_order_value: number;
  pct_of_sales: number;
}

export interface ReportPaymentMethodRow {
  payment_method: string;
  sales: number;
  orders_count: number;
  pct_of_sales: number;
}

export interface ReportResellerRow {
  reseller_id: number | null;
  reseller_name: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
  reseller_profit: number;
  avg_order_value: number;
}

export interface ReportOrderStatusFunnel {
  total: number;
  by_status: {
    not_started: number;
    processing: number;
    delivered: number;
    failed: number;
    needs_review: number;
    pending: number;
  };
  success_rate_pct: number;
}

export interface ReportReseller {
  id: number;
  business_name: string;
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : "";
}

export function listReportResellers(token: string) {
  return apiFetch<ReportReseller[]>("/api/reports/resellers", { token });
}

export function getReportSummary(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<ReportSummary>(`/api/reports/summary${query}`, { token });
}

export function getReportTrend(token: string, days: 7 | 14 | 30, resellerId?: number) {
  const query = buildQuery({ days, reseller_id: resellerId });
  return apiFetch<{ days: ReportTrendDay[] }>(`/api/reports/trend${query}`, { token });
}

export function getReportDailyBreakdown(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<{ days: ReportDailyBreakdownRow[] }>(`/api/reports/daily-breakdown${query}`, { token });
}

export function getTopGames(token: string, filters: ReportFilters, limit = 5) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId, limit });
  return apiFetch<{ games: ReportGameRow[] }>(`/api/reports/top-games${query}`, { token });
}

export function getGameBreakdown(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<{ games: ReportGameRow[] }>(`/api/reports/breakdown/games${query}`, { token });
}

export function getPaymentMethodBreakdown(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<{ payment_methods: ReportPaymentMethodRow[] }>(`/api/reports/breakdown/payment-methods${query}`, { token });
}

export function getResellerBreakdown(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<{ resellers: ReportResellerRow[] }>(`/api/reports/breakdown/resellers${query}`, { token });
}

export function getOrderStatusFunnel(token: string, filters: ReportFilters) {
  const query = buildQuery({ year: filters.year, month: filters.month, reseller_id: filters.resellerId });
  return apiFetch<ReportOrderStatusFunnel>(`/api/reports/order-status-funnel${query}`, { token });
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * RPT-3 export — bearer-token-gated binary download, same
 * fetch-as-blob-then-object-URL pattern as Backups' downloadBackupRun.
 */
export async function exportReport(
  token: string,
  format: "csv" | "pdf",
  filters: ReportFilters,
): Promise<void> {
  const query = buildQuery({
    format,
    year: filters.year,
    month: filters.month,
    reseller_id: filters.resellerId,
  });

  const response = await fetch(`${API_BASE_URL}/api/reports/export${query}`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Export failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `sales-report.${format}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
