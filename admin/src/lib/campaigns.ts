import { apiFetch } from "@/lib/api-client";
import { formatRm } from "@/components/reports/format";

/**
 * ADR-118 V14/V15/V17/V19 (PR 4): the Campaigns page API. Every money
 * figure is integer sen and computed server-side (ReportService::
 * campaignBreakdown, CampaignFeeSchedule); this file only carries it.
 */

export interface OrderBucket {
  sales_sen: number;
  profit_sen: number;
  orders: number;
}

export interface CodeScore {
  promo_code_id: number;
  code: string;
  clicks: number;
  redemptions: number;
  new_customers: number;
  sales_sen: number;
  redeemed: OrderBucket;
  attributed_only: OrderBucket;
  discount_sen: number;
}

export interface Scorecard {
  clicks: number;
  redemptions: number;
  new_customers: number;
  qualified_new_customers: number;
  median_first_order_sen: number | null;
  returned_customers: number;
  repeat_30d_customers: number;
  redeemed: OrderBucket;
  attributed_only: OrderBucket;
  discount_sen: number;
  fees_accrued_sen: number;
  cac_sen: number | null;
  net_sen: number;
  codes: CodeScore[];
}

export type Verdict =
  | { status: "in_trial"; month: number; of: number }
  | { status: "worth_keeping" }
  | { status: "above_target" }
  | null;

export interface CampaignSummary {
  id: number;
  name: string;
  ended_at: string | null;
  started_month: string | null;
  /** This month's fee from the schedule. */
  monthly_fee_sen: number;
  codes: string[];
  range: Scorecard;
  cumulative: Scorecard;
  verdict: Verdict;
}

export interface CampaignSettings {
  campaign_min_margin_percent: number;
  campaign_qualified_min_sen: number;
  campaign_target_cac_sen: number | null;
  campaign_trial_months: number | null;
}

export interface CampaignFee {
  id: number;
  monthly_fee_sen: number;
  from_month: string;
  to_month: string | null;
}

export interface PromoCodeRow {
  id: number;
  code: string;
  discount_percent: number;
  max_discount_sen: number | null;
  budget_sen: number;
  used_sen: number;
  is_active: boolean;
  is_redeemable_now: boolean;
  starts_at: string | null;
  ends_at: string | null;
  has_redemptions: boolean;
  over_cap: boolean;
  tracker_url: string;
}

export interface CampaignDetail extends Omit<CampaignSummary, "codes"> {
  fees: CampaignFee[];
  /** Positive balance = prepaid to the KOL; negative = owed. */
  balance: { month: string; accrued_sen: number; paid_sen: number; balance_sen: number };
  codes: PromoCodeRow[];
}

export interface RangeQuery {
  from?: string;
  to?: string;
}

const query = ({ from, to }: RangeQuery) => {
  const p = new URLSearchParams();
  if (from) p.set("from", from);
  if (to) p.set("to", to);
  const s = p.toString();
  return s ? `?${s}` : "";
};

export const listCampaigns = (range: RangeQuery) =>
  apiFetch<{ campaigns: CampaignSummary[]; settings: CampaignSettings }>(`/api/campaigns${query(range)}`);

export const getCampaign = (id: number, range: RangeQuery) => apiFetch<CampaignDetail>(`/api/campaigns/${id}${query(range)}`);

export const createCampaign = (body: { name: string; monthly_fee_sen: number; from_month: string; to_month: string | null }) =>
  apiFetch<{ id: number }>("/api/campaigns", { method: "POST", body });

export const updateCampaign = (id: number, body: { name?: string; ended?: boolean }) =>
  apiFetch<unknown>(`/api/campaigns/${id}`, { method: "PATCH", body });

export const addFee = (campaignId: number, body: { monthly_fee_sen: number; from_month: string; to_month: string | null }) =>
  apiFetch<{ id: number }>(`/api/campaigns/${campaignId}/fees`, { method: "POST", body });

export const setFeeEndMonth = (feeId: number, toMonth: string | null) =>
  apiFetch<unknown>(`/api/campaign-fees/${feeId}`, { method: "PATCH", body: { to_month: toMonth } });

export interface CodeValues {
  code?: string;
  discount_percent?: number;
  max_discount_sen?: number | null;
  budget_sen?: number;
  is_active?: boolean;
  starts_at?: string | null;
  ends_at?: string | null;
}

export const createCode = (campaignId: number, body: CodeValues) =>
  apiFetch<{ id: number; code: string }>(`/api/campaigns/${campaignId}/codes`, { method: "POST", body });

export const updateCode = (codeId: number, body: CodeValues) =>
  apiFetch<unknown>(`/api/promo-codes/${codeId}`, { method: "PATCH", body });

export interface RejectedPackage {
  package_id: number;
  game_name: string;
  package_name: string;
  price_sen: number;
}

export const marginCheck = (discountPercent: number, maxDiscountSen: number | null) =>
  apiFetch<{ rejected: RejectedPackage[] }>(
    `/api/campaigns/margin-check?discount_percent=${discountPercent}${maxDiscountSen ? `&max_discount_sen=${maxDiscountSen}` : ""}`,
  );

export const saveCampaignSettings = (body: Partial<CampaignSettings>) =>
  apiFetch<CampaignSettings>("/api/campaigns/settings", { method: "PATCH", body });

/** Reports' money format ("RM 1,234.50"); a loss reads "−RM 500.00". */
export const rm = (sen: number | null | undefined) =>
  sen === null || sen === undefined ? "—" : sen < 0 ? `−${formatRm(-sen)}` : formatRm(sen);

/** "RM12.50" or "12.5" typed by the admin → sen; null when blank or not a number. */
export const toSen = (value: string): number | null => {
  const n = Number(value.replace(/[^0-9.]/g, ""));
  return value.trim() === "" || Number.isNaN(n) ? null : Math.round(n * 100);
};

export const monthLabel = (ym: string | null) =>
  ym ? new Date(`${ym}-01T00:00:00Z`).toLocaleDateString("en-MY", { month: "short", year: "numeric", timeZone: "UTC" }) : "—";

export const verdictLabel = (v: Verdict): { text: string; tone: "info" | "success" | "danger" } | null => {
  if (!v) return null;
  if (v.status === "in_trial") return { text: `In trial (month ${v.month} of ${v.of})`, tone: "info" };
  if (v.status === "worth_keeping") return { text: "Worth keeping", tone: "success" };
  return { text: "Above target CAC", tone: "danger" };
};
