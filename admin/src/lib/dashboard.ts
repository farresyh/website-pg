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

export interface DashboardHealthSupplier {
  id: number;
  name: string;
  slug: string;
  balance: number;
  circuit_state: "closed" | "open";
}

export interface DashboardHealth {
  suppliers: DashboardHealthSupplier[];
  suppliers_definition: string;
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
  reseller_profit: number;
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
