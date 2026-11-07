"use client";

import { ArrowRight, CaretDown } from "@phosphor-icons/react/dist/ssr";
import type { MembershipExample } from "@/lib/membership";
import { formatRm, SPEND_MAX_RM, SPEND_MIN_RM, SPEND_PICKS_RM, type PlanEstimate } from "@/lib/membership-calculator";
import Button from "@/components/ui/Button";

/**
 * The plan chooser. The first estimate is the featured plan, a full card
 * with a button at every spend. On a phone the other plan is a row that
 * opens, so the answer stays on the slider's screen; on desktop every plan
 * is a card. Below break-even it leads with the saving and shows the fee
 * and the break-even beside it, never a net loss figure (ADR-055
 * 2026-11-07 addendum).
 */
export default function MembershipCalculator({
  spendRm,
  onSpendChange,
  best,
  estimates,
  example,
  onChoosePlan,
}: {
  spendRm: number;
  onSpendChange: (spendRm: number) => void;
  best: PlanEstimate | null;
  estimates: PlanEstimate[];
  example: MembershipExample | null;
  onChoosePlan: (planId: number) => void;
}) {
  const fill = ((spendRm - SPEND_MIN_RM) / (SPEND_MAX_RM - SPEND_MIN_RM)) * 100;
  const [featured, ...others] = estimates;

  return (
    <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] lg:gap-6 lg:rounded-lg lg:border-2 lg:border-ink lg:bg-surface-container-lowest lg:p-7 lg:neo-lg">
      <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
        <p className="font-display text-xs font-bold uppercase tracking-[0.08em] text-on-surface-variant">I top up about</p>
        <p className="mt-1 font-mono text-[44px] font-bold leading-tight lg:text-[52px]">
          RM{spendRm}
          <span className="ml-1 font-sans text-[15px] font-normal text-on-surface-variant">/month</span>
        </p>

        <div className="mt-3 grid grid-cols-4 gap-2">
          {SPEND_PICKS_RM.map((pick) => (
            <button
              key={pick}
              type="button"
              aria-pressed={pick === spendRm}
              onClick={() => onSpendChange(pick)}
              className={`min-h-11 rounded-md border-2 border-ink font-display text-[13px] font-bold ${
                pick === spendRm ? "bg-primary text-on-primary" : "bg-surface-container-lowest text-on-surface"
              }`}
            >
              RM{pick}
            </button>
          ))}
        </div>

        <input
          type="range"
          min={SPEND_MIN_RM}
          max={SPEND_MAX_RM}
          step={10}
          value={spendRm}
          onChange={(e) => onSpendChange(Number(e.target.value))}
          aria-label="Monthly top-up spend"
          aria-valuetext={`RM${spendRm} a month`}
          style={{ "--fill": `${fill}%` } as React.CSSProperties}
          className="range-neo mt-2"
        />
        <div className="flex justify-between font-mono text-xs text-on-surface-variant">
          <span>RM{SPEND_MIN_RM}</span>
          <span>RM{SPEND_MAX_RM.toLocaleString("en-MY")}</span>
        </div>

        {!best && featured.breakEvenRm !== null && (
          <p className="mt-3 text-sm leading-normal">
            {featured.plan.name} pays for itself at about RM{featured.breakEvenRm} a month
            {example && `, around ${Math.ceil(featured.breakEvenRm / example.regularRm)} top-ups of ${example.packageName}`}.
          </p>
        )}
      </div>

      <div className="flex flex-col gap-3 lg:grid lg:auto-rows-fr lg:grid-cols-2 lg:gap-4">
        <PlanCard estimate={featured} isFeatured isBest={featured === best} best={best} onChoosePlan={onChoosePlan} />

        {others.map((estimate) => (
          <div key={estimate.plan.id} className="contents">
            <details className="group rounded-lg border-2 border-ink bg-surface-container-low lg:hidden">
              <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 py-2.5">
                <span className="font-display text-[15px] font-bold">{estimate.plan.name}</span>
                <span className="ml-auto font-mono text-[15px] font-bold text-on-surface-variant">saves {formatRm(estimate.savingRm)}</span>
                <CaretDown size={16} weight="bold" className="shrink-0 transition-transform group-open:rotate-180" />
              </summary>
              <div className="border-t-2 border-ink p-4">
                <PlanDetails estimate={estimate} best={best} onChoosePlan={onChoosePlan} />
              </div>
            </details>
            <div className="hidden lg:block">
              <PlanCard estimate={estimate} best={best} onChoosePlan={onChoosePlan} />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function PlanCard({
  estimate,
  isFeatured = false,
  isBest = false,
  best,
  onChoosePlan,
}: {
  estimate: PlanEstimate;
  isFeatured?: boolean;
  isBest?: boolean;
  best: PlanEstimate | null;
  onChoosePlan: (planId: number) => void;
}) {
  const paysOff = estimate.netRm > 0;

  return (
    <div className={`flex h-full flex-col gap-1 rounded-lg border-2 border-ink p-4 lg:p-5 ${isFeatured ? "bg-primary-fixed text-on-primary-fixed" : "bg-surface-container-low"}`}>
      <div className="flex items-center justify-between gap-2">
        <p className="font-display text-headline-sm font-bold">{estimate.plan.name}</p>
        {isBest && (
          <span className="rounded-sm border-2 border-ink bg-warning px-2 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide text-on-warning">
            Best for you
          </span>
        )}
      </div>
      <p className="text-[13px] opacity-80">
        RM{estimate.plan.feeRm.toFixed(2)} for 30 days · up to RM{estimate.plan.quotaRm.toFixed(0)}
      </p>
      <p className="mt-1 font-display text-[11px] font-bold uppercase tracking-wide opacity-80">
        {paysOff ? "You keep, every 30 days" : "You save, every 30 days"}
      </p>
      <p className={`font-mono text-[38px] font-bold leading-tight ${isFeatured ? "text-primary-on-surface" : ""}`}>{formatRm(paysOff ? estimate.netRm : estimate.savingRm)}</p>
      <PlanDetails estimate={estimate} best={best} isFeatured={isFeatured} onChoosePlan={onChoosePlan} />
    </div>
  );
}

function PlanDetails({
  estimate,
  best,
  isFeatured = false,
  onChoosePlan,
}: {
  estimate: PlanEstimate;
  best: PlanEstimate | null;
  isFeatured?: boolean;
  onChoosePlan: (planId: number) => void;
}) {
  const { plan, savingRm, chargeRm, netRm, breakEvenRm, capped } = estimate;
  const paysOff = netRm > 0;

  return (
    <div className="mt-auto flex flex-col gap-1.5 text-sm">
      <dl className="flex flex-col gap-1 border-t border-ink pt-2">
        <div className="flex justify-between gap-3">
          <dt>Member prices save ({plan.realSavingsPercent ?? 0}%)</dt>
          <dd className="font-mono font-bold">{formatRm(savingRm)}</dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt>Membership cost</dt>
          <dd className="font-mono font-bold">{formatRm(chargeRm)}</dd>
        </div>
      </dl>

      {capped && <p className="text-[13px] opacity-80">Past this plan&apos;s RM{plan.quotaRm.toFixed(0)} limit, the rest is at the regular price.</p>}

      {isFeatured ? (
        <>
          {!paysOff && <p className="text-[13px] opacity-80">Not sure yet? Try it for 30 days. One payment, no auto-renewal.</p>}
          <Button onClick={() => onChoosePlan(plan.id)} className="mt-1.5 w-full justify-center">
            {paysOff ? `Subscribe to ${plan.name}` : `Try ${plan.name} for 30 days`}
          </Button>
        </>
      ) : (
        <>
          <p className="text-[13px] opacity-80">
            {paysOff && best ? `${best.plan.name} keeps you more at this spend.` : breakEvenRm !== null ? `Pays for itself from about RM${breakEvenRm} a month.` : ""}
          </p>
          <button
            type="button"
            onClick={() => onChoosePlan(plan.id)}
            className="flex min-h-11 items-center gap-1 self-start font-display text-xs font-bold uppercase tracking-wide text-primary-on-surface underline underline-offset-2"
          >
            Choose {plan.name} <ArrowRight size={14} weight="bold" />
          </button>
        </>
      )}
    </div>
  );
}
