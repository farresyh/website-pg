/**
 * ADR-139 (PR 4c): the shapes of GET /api/reseller-portal/rebate and its
 * claim, plus the pure helpers the Rebate page and the dashboard card share.
 * The reseller sees only "reach RM X → get RM Y" for its own tier (decision
 * 8): no share, cap, profit or markup exists in these types. Every amount
 * comes from the server; nothing here computes one.
 */

export type RebateStepState = "locked" | "claimable" | "claimed" | "expired";

export interface RebateStep {
  step: number;
  threshold_sen: number;
  /** This step's own reward (the ladder increment), before any monthly-limit adjustment. */
  reward_sen: number;
  state: RebateStepState;
  /** What the claim actually paid; set only on a claimed step. */
  paid_sen: number | null;
  adjusted: boolean;
}

export interface RebateMonth {
  /** "2026-12" */
  period: string;
  finalised: boolean;
  /** 00:00 KL on the 1st of the month after next: claims close at this instant (decision 9). */
  claim_deadline: string;
  sales_sen: number;
  steps: RebateStep[];
  adjustment: { amount_sen: number; state: "claimable" | "claimed" | "expired" } | null;
  next_threshold_sen: number | null;
  remaining_sen: number | null;
}

export interface RebateHistoryRow {
  period: string;
  kind: "step" | "adjustment";
  step: number;
  /** The step's target under the version it was reached on; null for the adjustment. */
  threshold_sen: number | null;
  amount_sen: number;
  adjusted: boolean;
  state: "claimed" | "expired";
  claimed_at: string | null;
  expired_at: string | null;
}

export interface RebatePage {
  eligible: boolean;
  tier_name: string | null;
  terms: { version: number; title: string; clauses: string[]; accepted: boolean };
  unspent_rebate_sen: number;
  /** This month and last month, each only while it has a ladder for this tier and is still claimable. */
  months: RebateMonth[];
  /** Terms addendum, decision 2: a saved table not in force yet, own tier only. */
  upcoming_ladder: { effective_from: string; steps: { step: number; threshold_sen: number; reward_sen: number }[] } | null;
  history: RebateHistoryRow[];
}

export interface RebateClaimResult {
  step: number;
  amount_sen: number;
  adjusted: boolean;
  balance_sen: number;
}

/**
 * Decision 11: steps are claimed in order (the server refuses otherwise).
 * Step 0 is the month's adjustment, which has no order and goes last.
 * "Claim all" walks this list; a single Claim button takes its head.
 */
export function claimQueue(month: RebateMonth): number[] {
  const steps = month.steps
    .filter((s) => s.state === "claimable")
    .map((s) => s.step)
    .sort((a, b) => a - b);
  return month.adjustment?.state === "claimable" ? [...steps, 0] : steps;
}

/** "2026-12" for the Malaysia month at `now`: which of the months is this month. */
export function currentKlPeriod(now: Date = new Date()): string {
  return now.toLocaleDateString("en-CA", { timeZone: "Asia/Kuala_Lumpur" }).slice(0, 7);
}

/** The deadline is the first instant claims close; a person reads the day before it, e.g. "31 Jan 2027". */
export function claimByDate(deadlineIso: string): string {
  return new Date(Date.parse(deadlineIso) - 1).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "Asia/Kuala_Lumpur",
  });
}

/** "2026-12" → "December 2026". */
export function formatRebatePeriod(period: string): string {
  return new Date(`${period}-01T12:00:00Z`).toLocaleDateString("en-GB", { month: "long", year: "numeric", timeZone: "UTC" });
}

/** "2027-01-01" → "1 January 2027". */
export function formatRebateDay(date: string): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
}

/** Decision 43: a statement line's detail, e.g. "RM5,000.00 step (December 2026)", the admin's wording. */
export function rebateEntryLabel(rebate: { period: string; kind: "step" | "adjustment"; step: number; threshold_sen: number | null }): string {
  const month = formatRebatePeriod(rebate.period);
  if (rebate.kind === "adjustment") return `Monthly adjustment (${month})`;
  const rm = ((rebate.threshold_sen ?? 0) / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `RM${rm} step (${month})`;
}
