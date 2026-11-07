import type { MembershipOfferPlan } from "@/lib/membership";

/**
 * The signed-out /membership page's estimate (ADR-055 2026-11-07
 * addendum). Display only: the real price is always computed server-side
 * at checkout. A plan's quota is spent in member prices (ADR-027 decision
 * 7), so the spend is converted before the cap is applied, and the fee is
 * the least the customer can really pay, payment fee included.
 */
export const SPEND_MIN_RM = 20;
export const SPEND_MAX_RM = 1000;
export const SPEND_DEFAULT_RM = 300;
export const SPEND_PICKS_RM = [50, 100, 300, 600];

export interface PlanEstimate {
  plan: MembershipOfferPlan;
  savingRm: number;
  chargeRm: number;
  netRm: number;
  /** The monthly spend where the saving covers the fee; null if the plan's cap comes first. */
  breakEvenRm: number | null;
  /** The spend is past the plan's cap, so the rest is at the regular price. */
  capped: boolean;
}

export function estimatePlan(plan: MembershipOfferPlan, spendRm: number): PlanEstimate {
  const rate = (plan.realSavingsPercent ?? 0) / 100;
  const chargeRm = plan.lowestChargeRm;
  const memberSpendRm = spendRm * (1 - rate);
  const savingRm = (Math.min(memberSpendRm, plan.quotaRm) * rate) / (1 - rate);
  const breakEvenRm = rate > 0 ? chargeRm / rate : null;

  return {
    plan,
    savingRm,
    chargeRm,
    netRm: savingRm - chargeRm,
    breakEvenRm: breakEvenRm !== null && breakEvenRm * (1 - rate) <= plan.quotaRm ? Math.round(breakEvenRm) : null,
    capped: memberSpendRm > plan.quotaRm,
  };
}

/** Best plan first; `best` is null when no plan pays for itself at this spend. */
export function comparePlans(plans: MembershipOfferPlan[], spendRm: number): { best: PlanEstimate | null; estimates: PlanEstimate[] } {
  const estimates = plans.map((plan) => estimatePlan(plan, spendRm));
  const ranked = [...estimates].sort((a, b) => b.netRm - a.netRm);
  const best = ranked[0] && ranked[0].netRm > 0 ? ranked[0] : null;

  return { best, estimates: best ? ranked : estimates };
}

export function formatRm(amountRm: number): string {
  return `${amountRm < 0 ? "-" : ""}RM${Math.abs(amountRm).toFixed(2)}`;
}
