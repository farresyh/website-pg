import { apiFetch } from "@/lib/api-client";

/**
 * DASH-1..6 (ADR-045). Mirrors backend/app/Services/Dashboard/DashboardService.php
 * — see that file's own doc comments for the grilled/pinned definitions
 * (cohort funnel, derived circuit-breaker state, reused delivery_reconciliation
 * thresholds, etc). This client only forwards params and renders whatever the
 * backend returns — no calculation here. Every metric also carries its own
 * `definition` string (decision 25), rendered via InfoTooltip.
 */

export interface MetricComparison {
  pct: number | null;
  direction: "up" | "down" | "flat" | "new";
}

export interface DashboardMetric {
  value: number;
  comparison: MetricComparison;
  definition: string;
}

export interface DashboardSummary {
  sales_today: DashboardMetric;
  orders_today: DashboardMetric;
  profit_today: DashboardMetric;
  vouchers_issued_today: DashboardMetric & { amount_sen: number };
}

/** ADR-083 decision 6 — null means drift_threshold isn't configured (not watched), never "in sync". */
export interface SupplierFundingDrift {
  ledger_balance: number;
  polled_balance: number;
  variance: number;
  threshold: number;
  is_drifted: boolean;
}

export interface DashboardHealthSupplier {
  id: number;
  name: string;
  slug: string;
  /** In this supplier's own currency — never assume MYR (Digiflazz is IDR). */
  balance: number;
  currency: string;
  /** Display-only conversion (CurrencyRateService, cached ~24h). Null when unavailable — never a hardcoded MYR label. */
  balance_myr_equivalent: number | null;
  /** ADR-069 — balance is below this supplier's api_config['low_balance_threshold']. */
  low_balance: boolean;
  drift: SupplierFundingDrift | null;
  circuit_state: "closed" | "open";
}

/**
 * 2026-09-15 addendum: the one place a supplier's own-currency balance
 * gets formatted for display — both /middleware's dashboard and
 * /admin's System Health render `DashboardHealthSupplier` (same
 * endpoint), and both used to either hardcode "RM" or show a bare
 * unlabeled number regardless of the real currency. Same IDR-has-no-
 * decimals convention `SupplierTransferModal`'s own `formatForeign()`
 * already uses, kept consistent rather than duplicated with drift.
 */
export function formatSupplierBalance(amount: number, currency: string): string {
  const decimals = currency === "IDR" ? 0 : 2;

  return `${currency} ${amount.toLocaleString("en-MY", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

export function formatMyrEquivalent(amount: number | null): string | null {
  if (amount === null) return null;

  return `≈ RM ${amount.toFixed(2)}`;
}

export interface DashboardHealth {
  suppliers: DashboardHealthSupplier[];
  suppliers_definition: string;
  /** PR-F build addendum decision 5 — last-known OpenWA session.status event, push- not poll-driven. Null = no event has ever arrived (not provisioned/linked), never assumed healthy. */
  openwa_session: { status: string; at: string } | null;
  openwa_session_definition: string;
  stuck_orders: { value: number; definition: string };
  pending_payments: { value: number; definition: string };
  queue: { pending: number; failed: number; definition: string };
}

export interface DashboardFunnel {
  window_days: number;
  created: { value: number; definition: string };
  payment_confirmed: { value: number; definition: string };
  delivered: { value: number; definition: string };
}

export interface DashboardTopGame {
  game_id: number | null;
  game_name: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
  affiliate_profit: number;
  avg_order_value: number;
  pct_of_sales: number;
  comparison: MetricComparison;
}

export interface DashboardTopGames {
  window_days: number;
  games: DashboardTopGame[];
  definition: string;
}

export interface DashboardHourlyActivity {
  date: string;
  hours: Array<{ hour: number; count: number }>;
  definition: string;
}

export function getDashboardSummary(token: string) {
  return apiFetch<DashboardSummary>("/api/dashboard/summary", { token });
}

export function getDashboardHealth(token: string) {
  return apiFetch<DashboardHealth>("/api/dashboard/health", { token });
}

export function getDashboardFunnel(token: string) {
  return apiFetch<DashboardFunnel>("/api/dashboard/funnel", { token });
}

export function getDashboardTopGames(token: string, limit = 5) {
  return apiFetch<DashboardTopGames>(`/api/dashboard/top-games?limit=${limit}`, { token });
}

export function getDashboardHourlyActivity(token: string, date: string) {
  return apiFetch<DashboardHourlyActivity>(`/api/dashboard/hourly-activity?date=${encodeURIComponent(date)}`, { token });
}
