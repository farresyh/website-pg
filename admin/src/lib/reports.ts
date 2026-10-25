import { apiFetch, apiDownload } from "@/lib/api-client";

/**
 * RPT-1..3. Mirrors backend/app/Services/Report/ReportService.php — see
 * that file's own doc comment for the sales/profit definitions grilled
 * and pinned 2026-08-26 (ledger-sourced profit, paid_at-scoped sales,
 * Asia/Kuala_Lumpur day-bucketing). This client only forwards filters
 * and renders whatever the backend returns — no calculation here.
 *
 * ADR-086 filter-unification follow-up (2026-09-11): the old separate
 * Year/Month picker is gone — `from`/`to` are KL calendar dates
 * ('YYYY-MM-DD'), resolved client-side from one preset control
 * (see reports/DateRangeFilter.tsx) and forwarded as-is to every tab,
 * the trend chart included. Both undefined means "All time".
 */
export interface ReportFilters {
  from?: string;
  to?: string;
  affiliateId?: number;
}

export interface ReportSummary {
  total_sales: number;
  orders_count: number;
  platform_profit: number;
  affiliate_profit: number;
  margin_pct: number;
  avg_order_value: number;
  latest_order: {
    id: number;
    order_number: string;
    paid_at: string;
    customer_email: string;
    final_amount: number;
  } | null;
  /** Present only when `compare` was requested; null for "All time". */
  compare?: ReportComparison | null;
}

/**
 * ADR-104 R12–R14 — backend `PeriodComparison`: `pct`/`points` are
 * absolute, `direction` carries the sign; "new" = previous period empty.
 */
export type ChangeDirection = "up" | "down" | "flat" | "new";
export interface PercentChange {
  pct: number | null;
  direction: ChangeDirection;
}
export interface PointsChange {
  points: number | null;
  direction: ChangeDirection;
}

export type CompareMode = "previous" | "month_to_date";

/** ADR-104 R12–R14 — backend `ReportService::compareWith()`: the same figures over the previous range, plus each change. */
export interface Comparison<Previous, Changes> {
  /** `until` (KL "HH:MM") is set when the range is still running and the previous one stops at the same time. */
  previous_range: { from: string; to: string; until: string | null };
  previous: Previous;
  changes: Changes;
}

export type ReportComparison = Comparison<
  Omit<ReportSummary, "latest_order" | "compare">,
  {
    total_sales: PercentChange;
    orders_count: PercentChange;
    platform_profit: PercentChange;
    affiliate_profit: PercentChange;
    avg_order_value: PercentChange;
    margin_pct: PointsChange;
  }
>;

/** ADR-104 R7 — Paid sales walked to recognised revenue; null under an affiliate filter. */
export interface ReportAccountingBridge {
  paid_sales: number;
  transaction_fees: number;
  voucher_discounts: number;
  not_recognised: number;
  recognised_from_other_periods: number;
  partial_compensation: number;
  recognised_revenue: number;
  unexplained_difference: number;
}

/** ADR-127 decision 9 — owner profit (market rate) walked to operating profit (accounts); null under an affiliate filter. Signed sen. */
export interface ReportProfitBridge {
  owner_profit: number;
  membership_revenue: number;
  payment_processing_gain_loss: number;
  bank_transfer_fees: number;
  affiliate_tier_fees: number;
  voucher_breakage: number;
  admin_issued_vouchers: number;
  supplier_manual_adjustments: number;
  cogs_funding_difference: number;
  manual_profit_corrections: number;
  operating_profit: number;
  unexplained_difference: number;
}

/** One delivery status's compensation (ADR-104 R8, revised 2026-10-09). */
export interface CompensationBlock {
  count: number;
  paid_amount: number;
  voucher_issued: number;
  wallet_refund: number;
  voucher_restored: number;
  /** Still needing compensation: Orders' Need action, same scope. */
  awaiting_compensation: number;
}

/**
 * ADR-104 R8–R10, revised 2026-10-09 — a block per delivery status.
 * Failed: a liability, never netted from profit. Partially delivered: the
 * compensation is already inside that order's profit. Other: compensation
 * on any other status (count = compensated orders, paid_amount 0).
 */
export interface ReportFailedCompensated {
  failed: CompensationBlock;
  partially_delivered: CompensationBlock;
  other: CompensationBlock;
  outstanding_store_credit: number;
  outstanding_store_credit_as_of: string;
}

export type ReportChannel = "own_brand" | "reseller_wallet" | "external_affiliate";
export type PlacedVia = "storefront" | "reseller_api" | "reseller_bot" | "sandbox";

/** R15 — the three exclusive channel buckets, in the backend's order. */
export const CHANNEL_LABELS: Record<ReportChannel, string> = {
  own_brand: "Own brand",
  reseller_wallet: "Reseller wallet",
  external_affiliate: "External affiliate",
};

/** ADR-104 R15/R16. */
export interface ReportChannelBreakdown {
  channels: {
    channel: ReportChannel;
    sales: number;
    orders_count: number;
    platform_profit: number;
    affiliate_profit: number;
  }[];
  reseller_wallet_by_placed_via: { placed_via: PlacedVia; sales: number; orders_count: number }[];
  /** R13 — each channel's sales change; present only when `compare` was requested, null for "All time". */
  compare?: Comparison<unknown, Record<ReportChannel, PercentChange>> | null;
}

/** ADR-104 R5. */
export interface ReportDeliveryByGameRow {
  game_id: number | null;
  game_name: string;
  image_url: string | null;
  total: number;
  delivered: number;
  failed: number;
  partially_delivered: number;
  in_progress: number;
  success_rate_pct: number;
}

export interface ReportTrendDay {
  date: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
  affiliate_profit: number;
}

export interface ReportDailyBreakdownRow {
  date: string;
  orders_count: number;
  sales: number;
  platform_profit: number;
  affiliate_profit: number;
  transaction_fees: number;
  avg_order_value: number;
}

export interface ReportGameRow {
  game_id: number | null;
  game_name: string;
  image_url: string | null;
  sales: number;
  orders_count: number;
  platform_profit: number;
  affiliate_profit: number;
  avg_order_value: number;
  pct_of_sales: number;
}

export interface ReportPaymentMethodRow {
  payment_method: string;
  sales: number;
  orders_count: number;
  pct_of_sales: number;
}

export interface ReportAffiliateRow {
  affiliate_id: number | null;
  affiliate_name: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
  affiliate_profit: number;
  avg_order_value: number;
}

/** ADR-086 PR-2 — Reseller-wallet breakdown, distinct from ReportAffiliateRow above. */
export interface ReportResellerRow {
  reseller_id: number;
  reseller_name: string;
  sales: number;
  orders_count: number;
  platform_profit: number;
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
    partially_delivered: number;
  };
  success_rate_pct: number;
}

export interface ReportMembershipBreakdown {
  member_sales: number;
  member_orders_count: number;
  standard_sales: number;
  standard_orders_count: number;
  margin_forgone: number;
  membership_fee_revenue: number;
  /** R13 — present only when `compare` was requested, null for "All time". */
  compare?: Comparison<unknown, Record<Exclude<keyof ReportMembershipBreakdown, "compare">, PercentChange>> | null;
}

/** ADR-104 2026-10-15 — paid orders per KL hour of payment, 0–23. */
export interface ReportBusyHour {
  hour: number;
  orders_count: number;
}

/** ADR-104 2026-10-15 — each fact is null when it has no data; best_day is also null for a one-day range. */
export interface ReportHighlights {
  best_day: { date: string; sales: number; orders_count: number; is_all_time_record: boolean } | null;
  top_reseller: { reseller_id: number; reseller_name: string; sales: number; orders_count: number } | null;
  biggest_order: { id: number; order_number: string; amount: number; game_name: string | null; package_name: string | null } | null;
}

export interface ReportAffiliate {
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

export function listReportAffiliates() {
  return apiFetch<ReportAffiliate[]>("/api/reports/affiliates");
}

export function getReportSummary(filters: ReportFilters, compare?: CompareMode) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId, compare });
  return apiFetch<ReportSummary>(`/api/reports/summary${query}`);
}

export function getAccountingBridge(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ bridge: ReportAccountingBridge | null; profit_bridge: ReportProfitBridge | null }>(`/api/reports/accounting-bridge${query}`);
}

export function getFailedCompensated(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<ReportFailedCompensated>(`/api/reports/failed-compensated${query}`);
}

export function getChannelBreakdown(filters: ReportFilters, compare?: CompareMode) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId, compare });
  return apiFetch<ReportChannelBreakdown>(`/api/reports/breakdown/channels${query}`);
}

export function getDeliveryByGame(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ games: ReportDeliveryByGameRow[] }>(`/api/reports/breakdown/delivery-by-game${query}`);
}

/** ADR-086 filter-unification follow-up — trend chart now follows the same filters as every other tab, no more its own days=7|14|30 param. */
export function getReportTrend(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ days: ReportTrendDay[] }>(`/api/reports/trend${query}`);
}

export function getReportDailyBreakdown(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ days: ReportDailyBreakdownRow[] }>(`/api/reports/daily-breakdown${query}`);
}

/** ADR-104 2026-10-15 — with `compare`, each listed game's sales change, keyed by game_id. */
export function getTopGames(filters: ReportFilters, limit = 5, compare?: CompareMode) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId, limit, compare });
  return apiFetch<{ games: ReportGameRow[]; compare?: Comparison<unknown, Record<string, PercentChange>> | null }>(`/api/reports/top-games${query}`);
}

export function getGameBreakdown(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ games: ReportGameRow[] }>(`/api/reports/breakdown/games${query}`);
}

export function getPaymentMethodBreakdown(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ payment_methods: ReportPaymentMethodRow[] }>(`/api/reports/breakdown/payment-methods${query}`);
}

export function getAffiliateBreakdown(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ affiliates: ReportAffiliateRow[] }>(`/api/reports/breakdown/affiliates${query}`);
}

export function getResellerBreakdown(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ resellers: ReportResellerRow[] }>(`/api/reports/breakdown/resellers${query}`);
}

export function getOrderStatusFunnel(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<ReportOrderStatusFunnel>(`/api/reports/order-status-funnel${query}`);
}

export function getBusyHours(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<{ hours: ReportBusyHour[] }>(`/api/reports/busy-hours${query}`);
}

export function getHighlights(filters: ReportFilters) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId });
  return apiFetch<ReportHighlights>(`/api/reports/highlights${query}`);
}

export function getMembershipBreakdown(filters: ReportFilters, compare?: CompareMode) {
  const query = buildQuery({ from: filters.from, to: filters.to, affiliate_id: filters.affiliateId, compare });
  return apiFetch<ReportMembershipBreakdown>(`/api/reports/membership-breakdown${query}`);
}


/**
 * RPT-3 export — auth-gated binary download, same
 * fetch-as-blob-then-object-URL pattern as Backups' downloadBackupRun.
 */
export async function exportReport(
  format: "csv" | "pdf",
  filters: ReportFilters,
): Promise<void> {
  const query = buildQuery({
    format,
    from: filters.from,
    to: filters.to,
    affiliate_id: filters.affiliateId,
  });

  await apiDownload(`/api/reports/export${query}`, `sales-report.${format}`);
}
