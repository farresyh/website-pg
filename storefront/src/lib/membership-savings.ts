/**
 * 2026-09-20 addendum — the one savings formula every customer-facing
 * membership badge must share. Before this, `discount_percent` (a "%
 * cut off package markup" admin config value) leaked into `/membership`'s
 * subscribe screen as if it were the real price-level savings a customer
 * gets — the mismatch that started this addendum. The real number is
 * always `(1 - memberPrice/standardPrice) * 100`, computed here once.
 */
export interface Savings {
  /** Rounded to the nearest whole percent, e.g. 8 for "8%". */
  percent: number;
  /** Absolute RM saved (unrounded) — round for display at the call site. */
  amountRm: number;
}

export function calculateSavings(standardRm: number, memberRm: number): Savings | null {
  if (standardRm <= 0 || memberRm >= standardRm) return null;

  const amountRm = standardRm - memberRm;
  const percent = Math.round((amountRm / standardRm) * 100);

  return percent > 0 ? { percent, amountRm } : null;
}

/**
 * The retail "Rule of 100" pricing-psychology threshold: below a RM100
 * reference price the percentage digit reads larger than the RM digit,
 * and vice versa at or above RM100 (the two are mathematically equal at
 * exactly RM100) — so a compact badge shows whichever number reads
 * bigger, not the same format regardless of price. `referenceRm` is the
 * price the savings is being compared against (the standard price),
 * never the savings amount itself.
 */
export function formatSavingsBadge(referenceRm: number, savings: Savings): string {
  return referenceRm >= 100 ? `RM${savings.amountRm.toFixed(2)}` : `${savings.percent}%`;
}
