import { apiDownload, apiFetch } from "@/lib/api-client";

/** ADR-139 PR 4b — the admin side of the reseller milestone rebate (endpoints from PR 4a). */

export interface RebateLadderStep {
  threshold_sen: number;
  /** Cumulative reward in sen, keyed by tier id. */
  rewards: Record<string, number>;
}

export interface RebateLadder {
  id: number;
  effective_from: string;
  max_share_bp: number;
  steps: RebateLadderStep[];
  saved_by: string | null;
  saved_at: string;
  status: "in_force" | "upcoming" | "past";
}

export interface RebateTier {
  id: number;
  name: string;
  markup_percent: string;
  is_active: boolean;
}

export interface RebateLadders {
  ladders: RebateLadder[];
  tiers: RebateTier[];
  /** Decision 44: active tiers with no column in the newest version earn nothing. */
  tiers_without_column: number[];
  /** The first 1st (KL) at least 7 days away: where a version saved now starts. */
  next_effective_from: string;
  hard_ceiling_bp: number;
}

export interface RebateMonthReseller {
  reseller_id: number;
  business_name: string;
  tier_id: number | null;
  eligible: boolean;
  closed: boolean;
  sales_sen: number;
  profit_sen: number;
  reached_step: number;
  cap_sen: number;
  entitlement_sen: number;
  claimed_sen: number;
  available_sen: number;
  expired_sen: number;
  adjusted: boolean;
}

export interface RebateMilestoneRow {
  id: number;
  reseller_id: number;
  business_name: string | null;
  period: string;
  kind: "step" | "adjustment";
  step: number;
  amount_sen: number;
  state: "available" | "claimed" | "expired";
  adjusted: boolean;
  ladder_id: number;
  tier_id: number | null;
  sales_sen: number;
  profit_sen: number;
  ladder_cumulative_sen: number;
  cap_sen: number;
  computed_at: string | null;
  claimed_at: string | null;
  expired_at: string | null;
}

export interface RebateMonth {
  period: string;
  finalised: boolean;
  claim_deadline: string;
  resellers: RebateMonthReseller[];
  rows: RebateMilestoneRow[];
}

export function getRebateLadders() {
  return apiFetch<RebateLadders>("/api/rebate/ladders");
}

/** Behind the MFA step-up; the api client asks for the code when needed. */
export function saveRebateLadder(steps: RebateLadderStep[], maxShareBp: number) {
  return apiFetch<{ id: number; effective_from: string }>("/api/rebate/ladders", {
    method: "POST",
    body: { steps, max_share_bp: maxShareBp },
  });
}

export function getRebateMonth(year: number, month: number) {
  return apiFetch<RebateMonth>(`/api/rebate/months/${year}/${month}`);
}

export async function downloadRebateMonth(year: number, month: number): Promise<void> {
  const period = `${year}-${String(month).padStart(2, "0")}`;
  await apiDownload(`/api/rebate/months/${year}/${month}/export`, `rebate-${period}.csv`);
}

/** Behind the MFA step-up; the reason is recorded with the change. */
export function setRebateEligibility(resellerId: number, eligible: boolean, reason: string) {
  return apiFetch<{ id: number; rebate_eligible: boolean }>(`/api/resellers/${resellerId}/rebate-eligibility`, {
    method: "PATCH",
    body: { eligible, reason },
  });
}

/** "2027-01-01" → "1 January 2027". */
export function formatRebateDate(date: string): string {
  return new Date(`${date}T00:00:00`).toLocaleDateString("en-MY", { day: "numeric", month: "long", year: "numeric" });
}

/** "2026-12" → "December 2026". */
export function formatRebatePeriod(period: string): string {
  return new Date(`${period}-01T00:00:00`).toLocaleDateString("en-MY", { month: "long", year: "numeric" });
}

/** Decision 43: a statement line's detail, e.g. "RM5,000.00 step (December 2026)". */
export function rebateEntryLabel(rebate: { period: string; kind: "step" | "adjustment"; step: number; threshold_sen: number | null }): string {
  const month = formatRebatePeriod(rebate.period);
  if (rebate.kind === "adjustment") return `Monthly adjustment (${month})`;
  const rm = ((rebate.threshold_sen ?? 0) / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `RM${rm} step (${month})`;
}

/**
 * The paper ceiling RebateLadderService enforces on save: a tier's
 * cumulative reward at a step is at most 50% of the profit its threshold
 * earns at that tier's markup, threshold × m / (100 + m). Shown so the
 * editor can flag a cell before the server refuses it.
 */
export function rebateCeilingSen(thresholdSen: number, markupPercent: number, ceilingBp: number): number {
  return Math.floor((ceilingBp / 10000) * thresholdSen * (markupPercent / (100 + markupPercent)));
}
