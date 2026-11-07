import type { MembershipOfferPlan } from "@/lib/membership";

/**
 * The signed-out /membership page's estimate (ADR-055 2026-11-07
 * addendum). Display only: the real price is always computed server-side
 * at checkout. A plan's quota is spent in member prices (ADR-027 decision
 * 7), so the spend is converted before the cap is applied, and the fee is
 * the least the customer can really pay, payment fee included. The fee is
 * always shown beside the saving, and the break-even stays visible: the
 * page leads with what a plan gives, it never hides what it costs.
 */
export const SPEND_MIN_RM = 100;
export const SPEND_MAX_RM = 1000;
export const SPEND_DEFAULT_RM = 300;
export const SPEND_PICKS_RM = [100, 200, 300, 600];

export interface PlanEstimate {
  plan: MembershipOfferPlan;
  savingRm: number;
  chargeRm: number;
  netRm: number;
  /** The monthly spend where the saving covers the fee; null if the plan's cap comes first. */
  breakEvenRm: number | null;
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
  };
}

export interface PlanComparison {
  /** The plan that leaves the most after its fee, when one pays for itself at this spend. */
  best: PlanEstimate | null;
  /** The plan the page leads with: `best`, else the one that pays for itself soonest. */
  featured: PlanEstimate;
  /** Featured plan first. */
  estimates: PlanEstimate[];
}

export function comparePlans(plans: MembershipOfferPlan[], spendRm: number): PlanComparison {
  const estimates = plans.map((plan) => estimatePlan(plan, spendRm));
  const byNet = [...estimates].sort((a, b) => b.netRm - a.netRm);
  const best = byNet[0].netRm > 0 ? byNet[0] : null;
  const soonest = [...estimates].sort((a, b) => (a.breakEvenRm ?? Infinity) - (b.breakEvenRm ?? Infinity))[0];
  const featured = best ?? soonest;

  return { best, featured, estimates: [featured, ...estimates.filter((estimate) => estimate !== featured)] };
}

export function formatRm(amountRm: number): string {
  return `${amountRm < 0 ? "-" : ""}RM${Math.abs(amountRm).toFixed(2)}`;
}
