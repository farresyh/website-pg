import Button from "@/components/ui/Button";
import type { MembershipPlan } from "@/lib/membership";

interface MembershipPromoCardProps {
  /** The tier this card always promotes — Tier 2 (the top tier), ADR-055 decision 2. */
  plan: MembershipPlan;
  packageName: string;
  /** The selected package's standard (guest) price, RM. */
  sellingPriceRm: number;
  /** The selected package's member price at `plan`'s discount, RM — backend-computed, never derived client-side (ORD-9). */
  memberPriceRm: number;
  /** The visitor's own tier name (getMe), or null for a guest — swaps the CTA phrase, ADR-055 decision 6. */
  activeTierName: string | null;
}

/**
 * ADR-055: the storefront's membership upsell teaser — "unlock {package}
 * at {Tier 2 price}, save {%/RM}, subscribe for only {monthly fee}".
 * Deliberately a teaser only: the CTA links to /membership's existing
 * "coming soon" state, never to a real subscribe flow (decision 1 —
 * self-serve subscribe+pay is Phase 7, its own scope). Always promotes
 * Tier 2 specifically; the two display numbers (`sellingPriceRm`,
 * `memberPriceRm`) come straight from the backend so the savings row is
 * a pure rendering of two server-provided values, not new client math.
 */
export default function MembershipPromoCard({
  plan,
  packageName,
  sellingPriceRm,
  memberPriceRm,
  activeTierName,
}: MembershipPromoCardProps) {
  const savingsRm = sellingPriceRm - memberPriceRm;
  const savingsPercent = sellingPriceRm > 0 ? (1 - memberPriceRm / sellingPriceRm) * 100 : 0;
  const ctaLabel = activeTierName ? "Upgrade to Tier 2" : "Become a Member";

  return (
    <div className="rounded-lg border-2 border-ink bg-secondary-container p-5 neo">
      <p className="mb-2 font-display text-sm font-bold uppercase tracking-wide text-on-secondary-container">Membership</p>
      <p className="text-[13.5px] leading-snug text-on-secondary-container">
        Unlock <span className="font-bold">{packageName}</span> at{" "}
        <span className="font-mono font-bold">RM{memberPriceRm.toFixed(2)}</span>, save{" "}
        <span className="font-bold">
          RM{savingsRm.toFixed(2)} ({savingsPercent.toFixed(0)}%)
        </span>{" "}
        on every purchase.
      </p>
      <p className="mt-2 text-[12.5px] text-on-secondary-container/80">
        Subscribe for only RM{(plan.feeSen / 100).toFixed(2)}/month.
      </p>
      <Button href="/membership" variant="outline" size="sm" className="mt-3 w-full">
        {ctaLabel}
      </Button>
    </div>
  );
}
