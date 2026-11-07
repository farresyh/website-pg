"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { ArrowDown, ArrowRight, CaretDown, Check } from "@phosphor-icons/react/dist/ssr";
import type { MembershipExample, MembershipOffer, MembershipOfferPlan } from "@/lib/membership";
import { comparePlans, formatRm, SPEND_DEFAULT_RM } from "@/lib/membership-calculator";
import Button from "@/components/ui/Button";
import MembershipCalculator from "@/components/order/MembershipCalculator";

/**
 * The signed-out /membership page (ADR-055 2026-11-07 addendum). Mobile
 * first, because most customers are on a phone: the offer and one real
 * price come first, the email only after a plan is chosen. Every number
 * comes from the offer endpoint; the copy states the real rules (ADR-027,
 * ADR-068): 30 days paid once, no auto-renewal, quota spent at member
 * prices, and an order over the remaining quota pays the regular price.
 */
export default function MembershipLanding({ offer, onSignIn }: { offer: MembershipOffer; onSignIn: (planId: number | null) => void }) {
  const [spendRm, setSpendRm] = useState(SPEND_DEFAULT_RM);
  const { best, featured, estimates } = useMemo(() => comparePlans(offer.plans, spendRm), [offer.plans, spendRm]);

  const calculatorRef = useRef<HTMLElement>(null);
  const finalCtaRef = useRef<HTMLElement>(null);
  const [pastCalculator, setPastCalculator] = useState(false);
  const [atEnd, setAtEnd] = useState(false);

  // The sticky button appears once the calculator's own button has gone up
  // behind the header, and leaves when the final call to action arrives.
  // The huge margins make "above the viewport" count as intersecting, so a
  // jump straight past a section still fires.
  useEffect(() => {
    const past = new IntersectionObserver(([entry]) => setPastCalculator(!entry.isIntersecting), { rootMargin: "-120px 0px 100000px 0px" });
    const end = new IntersectionObserver(([entry]) => setAtEnd(entry.isIntersecting), { rootMargin: "100000px 0px 0px 0px" });
    if (calculatorRef.current) past.observe(calculatorRef.current);
    if (finalCtaRef.current) end.observe(finalCtaRef.current);
    return () => {
      past.disconnect();
      end.disconnect();
    };
  }, []);

  const example = offer.examples[0] ?? null;
  const cheapest = offer.plans.reduce<MembershipOfferPlan | null>((low, plan) => (low === null || plan.feeRm < low.feeRm ? plan : low), null);
  // The hero's example shows the deepest saving; the fee line stays the entry price.
  const topPlan = offer.plans.reduce<MembershipOfferPlan | null>(
    (top, plan) => (top === null || (plan.realSavingsPercent ?? 0) > (top.realSavingsPercent ?? 0) ? plan : top),
    null,
  );
  const topPlanIndex = topPlan ? offer.plans.indexOf(topPlan) : 0;

  return (
    <div className="mx-auto flex max-w-[1200px] flex-col gap-12 px-4 py-6 lg:gap-20 lg:py-14">
      <section className="grid gap-8 lg:grid-cols-[1fr_520px] lg:items-center">
        <div className="flex flex-col items-start gap-4">
          <span className="rounded-md border-2 border-ink bg-warning px-3 py-1 font-display text-xs font-bold uppercase tracking-[0.08em] text-on-warning">
            Membership
          </span>
          <h1 className="font-display text-[38px] font-bold uppercase leading-[1.05] tracking-tight lg:text-[56px]">
            Pay less on every <span className="whitespace-nowrap">top-up.</span>
          </h1>
          <p className="text-[15px] leading-normal text-on-surface-variant lg:text-lg">
            Become a member and pay less for your top-ups.
            {cheapest && (
              <>
                {" "}
                <span className="font-semibold text-on-surface">From RM{cheapest.feeRm.toFixed(2)} for 30 days</span>, plus a small payment fee.
              </>
            )}
          </p>

          {example && topPlan && (
            <div className="w-full lg:hidden">
              <SampleReceipt example={example} plan={topPlan} planIndex={topPlanIndex} />
            </div>
          )}

          <a
            href="#calculator"
            className="flex min-h-12 w-full items-center justify-center gap-2 rounded-md border-2 border-ink bg-primary px-6 py-3 font-display text-[15px] font-bold uppercase tracking-wide text-on-primary neo neo-hover transition-all lg:w-auto"
          >
            See how much you save <ArrowDown size={16} weight="bold" />
          </a>

          <button
            type="button"
            onClick={() => onSignIn(null)}
            className="min-h-11 text-sm font-semibold text-on-surface underline underline-offset-2"
          >
            Already a member? Sign in
          </button>

          <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[13px] font-medium text-on-surface-variant">
            {["No password", "No auto-renewal", "Secured by CHIP"].map((item) => (
              <li key={item} className="flex items-center gap-1">
                <Check size={14} weight="bold" className="text-success" /> {item}
              </li>
            ))}
          </ul>
        </div>

        {example && topPlan && (
          <div className="relative hidden h-[440px] overflow-hidden rounded-lg border-2 border-ink bg-warning neo-lg lg:block">
            {offer.isPrimaryBrand && (
              <>
                <Image src="/images/membership/mascot-pointing.png" alt="" width={397} height={760} priority className="absolute -bottom-3 left-6 h-[430px] w-auto" />
                <p className="absolute right-6 top-8 w-[230px] rounded-lg border-2 border-ink bg-surface-container-lowest px-5 py-4 font-display text-[22px] font-bold uppercase leading-tight text-on-surface neo-sm">
                  You could be paying less.
                  <span aria-hidden className="absolute -left-[9px] top-8 size-4 rotate-45 border-b-2 border-l-2 border-ink bg-surface-container-lowest" />
                </p>
              </>
            )}
            <div className={`absolute w-[268px] ${offer.isPrimaryBrand ? "bottom-7 right-6" : "left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"}`}>
              <SampleReceipt example={example} plan={topPlan} planIndex={topPlanIndex} />
            </div>
          </div>
        )}
      </section>

      <section id="calculator" ref={calculatorRef} className="scroll-mt-32">
        <Heading title="How much will you save?" subtitle="Pick how much you top up in a month." />
        <MembershipCalculator spendRm={spendRm} onSpendChange={setSpendRm} best={best} estimates={estimates} example={example} onChoosePlan={onSignIn} />
        <p className="mt-3 text-[13px] text-on-surface-variant">
          These are estimates. What you save depends on what you buy. Membership cost uses the lowest payment fee.
        </p>
      </section>

      {offer.examples.length > 0 && (
        <section>
          <Heading title="See the difference" subtitle="Same top-up, lower price. Popular packages today, with and without a membership." />
          <PriceComparison examples={offer.examples} plans={offer.plans} />
        </section>
      )}

      <section>
        <Heading title="How it works" />
        <ol className="grid gap-3 lg:grid-cols-3 lg:gap-6">
          {[
            ["Pick a plan", "The calculator above shows which one suits you."],
            ["Check your email", "We send you a code. No password to remember."],
            ["Pay once, then pay less", "One payment covers 30 days. Sign in with your email and member prices show up at checkout."],
          ].map(([title, body], index) => (
            <li key={title} className="flex gap-3 rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo lg:flex-col lg:p-6">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 border-ink bg-secondary-container font-display font-bold text-on-secondary-container">
                {index + 1}
              </span>
              <div>
                <p className="font-display text-[17px] font-bold">{title}</p>
                <p className="mt-1 text-sm leading-normal text-on-surface-variant">{body}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section>
        <Heading title="Membership questions" />
        <div className="flex flex-col gap-3">
          {faqs(offer.plans).map(({ question, answer }, index) => (
            <details key={question} open={index === 0} className="group rounded-lg border-2 border-ink bg-surface-container-lowest neo">
              <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 p-4 font-display text-[15px] font-bold lg:p-5">
                {question}
                <CaretDown size={18} weight="bold" className="shrink-0 transition-transform group-open:rotate-180" />
              </summary>
              <p className="px-4 pb-4 text-sm leading-relaxed text-on-surface-variant lg:px-5 lg:pb-5">{answer}</p>
            </details>
          ))}
        </div>
      </section>

      <section ref={finalCtaRef} className="flex flex-col gap-5 rounded-lg border-2 border-ink bg-primary p-6 text-on-primary neo lg:flex-row lg:items-center lg:justify-between lg:p-10">
        <div>
          <p className="font-display text-[32px] font-bold uppercase leading-tight tracking-tight">{best ? "Ready to pay less?" : "Not sure yet?"}</p>
          <p className="mt-1 text-[15px]">
            {best
              ? `With ${best.plan.name}, you'd have about ${formatRm(best.netRm)} left after paying for membership.`
              : `Try ${featured.plan.name} for 30 days, from ${formatRm(featured.chargeRm)}. You pay once, and it never renews by itself.`}
          </p>
        </div>
        <button type="button" onClick={() => onSignIn(featured.plan.id)} className={FINAL_BUTTON_CLASS}>
          {best ? "Subscribe to" : "Try"} {featured.plan.name} <ArrowRight size={16} weight="bold" />
        </button>
      </section>

      {pastCalculator && !atEnd && (
        <div className="fixed inset-x-4 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 lg:hidden">
          <Button onClick={() => onSignIn(featured.plan.id)} className="w-full justify-center">
            {best ? `Subscribe to ${best.plan.name}` : `Try ${featured.plan.name} for 30 days`} <ArrowRight size={16} weight="bold" />
          </Button>
        </div>
      )}
    </div>
  );
}

const FINAL_BUTTON_CLASS =
  "flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-md border-2 border-ink bg-warning px-6 py-3 font-display text-[15px] font-bold uppercase tracking-wide text-on-warning neo-sm";

function Heading({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <div className="mb-5 border-b-2 border-ink pb-3">
      <h2 className="font-display text-[26px] font-bold uppercase leading-tight tracking-tight lg:text-headline-lg">{title}</h2>
      {subtitle && <p className="mt-1.5 text-[15px] text-on-surface-variant">{subtitle}</p>}
    </div>
  );
}

function SampleReceipt({ example, plan, planIndex }: { example: MembershipExample; plan: MembershipOfferPlan; planIndex: number }) {
  const memberRm = example.memberRm[planIndex];

  return (
    <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-3.5 neo-sm">
      <p className="font-display text-xs font-bold uppercase tracking-wide">
        {example.gameName} · {example.packageName}
      </p>
      <div className="mt-2 flex items-center justify-between gap-3 text-sm text-on-surface-variant">
        Regular <span className="font-mono line-through">{formatRm(example.regularRm)}</span>
      </div>
      <div className="flex items-center justify-between gap-3 text-sm font-semibold">
        {plan.name} member <span className="font-mono text-lg font-bold text-primary-on-surface">{formatRm(memberRm)}</span>
      </div>
      <p className="mt-1.5 inline-block rounded-full border border-ink bg-warning px-2.5 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide text-on-warning">
        You save {formatRm(example.regularRm - memberRm)}
      </p>
    </div>
  );
}

function savedLabel(regularRm: number, memberRm: number): string {
  return `-${((1 - memberRm / regularRm) * 100).toFixed(1)}%`;
}

function PriceComparison({ examples, plans }: { examples: MembershipExample[]; plans: MembershipOfferPlan[] }) {
  return (
    <>
      <div className="flex flex-col gap-3 lg:hidden">
        {examples.slice(0, 3).map((example) => (
          <div key={`${example.gameName}-${example.packageName}`} className="rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo">
            <p className="font-display text-[15px] font-bold">
              {example.gameName} · {example.packageName}
            </p>
            <dl className="mt-3 grid grid-cols-3 gap-2">
              <div>
                <dt className="font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">Regular</dt>
                <dd className="font-mono text-[15px] text-on-surface-variant">{formatRm(example.regularRm)}</dd>
              </div>
              {plans.map((plan, index) => (
                <div key={plan.id}>
                  <dt className="font-display text-[11px] font-bold uppercase tracking-wide">
                    {plan.name} <span className="text-on-surface-variant">{savedLabel(example.regularRm, example.memberRm[index])}</span>
                  </dt>
                  <dd className="font-mono text-[15px] font-bold text-primary-on-surface">{formatRm(example.memberRm[index])}</dd>
                </div>
              ))}
            </dl>
          </div>
        ))}
      </div>

      <div className="hidden overflow-hidden rounded-lg border-2 border-ink bg-surface-container-lowest neo lg:block">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b-2 border-ink bg-surface-container-high font-display text-xs font-bold uppercase tracking-wide">
              <th className="p-4">Package</th>
              <th className="p-4">Regular price</th>
              {plans.map((plan) => (
                <th key={plan.id} className="p-4">
                  {plan.name} member
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/15">
            {examples.map((example) => (
              <tr key={`${example.gameName}-${example.packageName}`}>
                <td className="p-4 font-medium">
                  {example.gameName} · {example.packageName}
                </td>
                <td className="p-4 font-mono text-on-surface-variant">{formatRm(example.regularRm)}</td>
                {plans.map((plan, index) => (
                  <td key={plan.id} className="p-4 font-mono font-bold">
                    {formatRm(example.memberRm[index])}{" "}
                    <span className="text-xs font-semibold text-on-surface-variant">{savedLabel(example.regularRm, example.memberRm[index])}</span>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

function faqs(plans: MembershipOfferPlan[]): { question: string; answer: React.ReactNode }[] {
  const limits = plans.map((plan) => `RM${plan.quotaRm.toFixed(0)} with ${plan.name}`).join(" or ");

  return [
    {
      question: "How long does membership last?",
      answer: `30 days from the day you join. In that time you get member prices on up to ${limits}. Your limit fills up again every 30 days while you're a member.`,
    },
    {
      question: "Will it charge me again?",
      answer: "No. You pay once for 30 days, by online banking or QR. We don't keep your card, and nothing renews by itself. We email you before it ends, so you can renew if you want to.",
    },
    {
      question: "Can I cancel?",
      answer: "There's nothing to cancel. If you don't renew, it just ends after 30 days.",
    },
    {
      question: "What happens when I reach my limit?",
      answer: "You can still top up, at the regular price. If an order costs more than what's left of your limit, the whole order is at the regular price. Member prices come back when your limit fills up again.",
    },
    {
      question: "Does it work on every game?",
      answer: "It works on every game that shows a member price. Services like joki aren't included.",
    },
    {
      question: "Can I get a refund?",
      answer: (
        <>
          If your membership can&apos;t be switched on after you pay, we&apos;ll fix it or give you a store voucher for the fee. See our{" "}
          <Link href="/refund-policy" className="font-semibold text-primary-on-surface underline underline-offset-2">
            Refund Policy
          </Link>
          .
        </>
      ),
    },
    {
      question: "How is my email used?",
      answer: "We use it to send your sign-in code, receipts and renewal reminders, and to recognise you as a member at checkout.",
    },
  ];
}
