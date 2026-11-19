import { apiDownload, apiFetch } from "@/lib/api-client";
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

/** ADR-118 V26/V31: the deal for a month — a fixed fee, a share of recognised sales, or both. Not an amount. */
export interface FeeTerms {
  monthly_fee_sen: number;
  /** Basis points: 200 = 2%. */
  revenue_share_bp: number;
  revenue_share_cap_sen: number | null;
}

export interface CampaignSummary {
  id: number;
  name: string;
  ended_at: string | null;
  started_month: string | null;
  /** This month's terms; null when no fee row covers it. */
  terms: FeeTerms | null;
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

export interface CampaignFee extends FeeTerms {
  id: number;
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

/** ADR-118 V25: one customer a campaign brought in, all time. */
export interface CampaignCustomer {
  email: string;
  phone: string | null;
  code: string;
  first_order_number: string;
  first_paid_at: string;
  first_order_sales_sen: number;
  later_orders: number;
  later_sales_sen: number;
  last_paid_at: string;
}

export interface CampaignDetail extends Omit<CampaignSummary, "codes"> {
  fees: CampaignFee[];
  customers: CampaignCustomer[];
  /** Positive balance = prepaid to the KOL; negative = owed. */
  balance: {
    month: string;
    accrued_sen: number;
    paid_sen: number;
    balance_sen: number;
    /** V28: this month so far, live until the close freezes it. */
    this_month: { sales_sen: number; share_sen: number; limited_by: Limit; payable_sen: number };
  };
  codes: PromoCodeRow[];
  statements: CampaignStatement[];
}

/** V28: which limit cut the share, if any. */
export type Limit = "profit" | "cap" | null;

/** V30: one per closed month; a voided one was replaced by a re-close. */
export interface CampaignStatement {
  id: number;
  month: string;
  sales_sen: number;
  share_sen: number;
  payable_sen: number;
  limited_by: Limit;
  orders: number;
  voided: boolean;
}

export interface FeeValues {
  monthly_fee_sen: number;
  revenue_share_bp: number;
  revenue_share_cap_sen: number | null;
  from_month: string;
  to_month: string | null;
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

export const createCampaign = (body: FeeValues & { name: string }) =>
  apiFetch<{ id: number }>("/api/campaigns", { method: "POST", body });

export const updateCampaign = (id: number, body: { name?: string; ended?: boolean; last_fee_month?: string }) =>
  apiFetch<unknown>(`/api/campaigns/${id}`, { method: "PATCH", body });

export const addFee = (campaignId: number, body: FeeValues) =>
  apiFetch<{ id: number }>(`/api/campaigns/${campaignId}/fees`, { method: "POST", body });

/** V30: the Sales Statement PDF of a closed month, for the KOL. */
export const downloadStatement = (s: CampaignStatement, campaignName: string) =>
  apiDownload(`/api/campaign-statements/${s.id}/pdf`, `statement-${campaignName.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "")}-${s.month}.pdf`);

const percent = (bp: number) => `${(bp / 100).toFixed(2).replace(/\.?0+$/, "")}%`;

/** "RM500 + 2% of sales (cap RM100)", "2% of sales", "RM500" — the deal, never an amount. */
export const termsLabel = (t: FeeTerms | null) => {
  if (!t) return "—";
  const share = t.revenue_share_bp > 0 ? `${percent(t.revenue_share_bp)} of sales${t.revenue_share_cap_sen !== null ? ` (cap ${rm(t.revenue_share_cap_sen)})` : ""}` : null;
  if (share && t.monthly_fee_sen > 0) return `${rm(t.monthly_fee_sen)} + ${share}`;
  return share ?? rm(t.monthly_fee_sen);
};

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
