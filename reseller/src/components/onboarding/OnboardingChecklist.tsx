"use client";

import { useState } from "react";
import Link from "next/link";
import { Badge, buttonClass } from "@/components/composites";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { progress, requiredDone, stepCopy, type Onboarding, type OnboardingStep, type OwnerType } from "@/lib/onboarding";

/** ADR-133 decision 15: collapsed by the partner or by itself once every required step is done. */
const STORAGE_KEY = (ownerType: OwnerType) => `pg-onboarding-collapsed:${ownerType}`;

function readCollapsed(ownerType: OwnerType): boolean | null {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY(ownerType));
    return stored === null ? null : stored === "1";
  } catch {
    return null;
  }
}

export default function OnboardingChecklist({ ownerType, onboarding }: { ownerType: OwnerType; onboarding: Onboarding }) {
  const [chosen, setChosen] = useState<boolean | null>(() => readCollapsed(ownerType));
  const { done, total } = progress(onboarding.steps);
  const complete = requiredDone(onboarding.steps);
  // The partner's own choice wins; otherwise it closes itself once there is nothing left that blocks them.
  const collapsed = chosen ?? complete;
  const firstOpen = onboarding.steps.find((s) => s.state === "todo" || s.state === "waiting_on_us");

  function toggle() {
    const next = !collapsed;
    setChosen(next);
    try {
      window.localStorage.setItem(STORAGE_KEY(ownerType), next ? "1" : "0");
    } catch {
      // private mode: the choice just lasts until the page is reloaded
    }
  }

  return (
    <section aria-label="Get started" className="mb-4 overflow-hidden rounded-lg border border-border bg-surface shadow-xs">
      <div className="flex items-center gap-4 px-5 py-4">
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-[22px] text-ink">{complete ? "You are set up" : "Get started"}</h2>
          <p className="mt-0.5 text-xs text-ink-subtle">
            {done} of {total} done
            {!complete && firstOpen ? ` · next: ${stepCopy(ownerType, firstOpen.key).title}` : ""}
          </p>
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-subtle" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={done}>
            <div className="h-full rounded-full bg-cyan-600 transition-[width]" style={{ width: `${total === 0 ? 0 : Math.round((done / total) * 100)}%` }} />
          </div>
        </div>
        <button
          type="button"
          onClick={toggle}
          aria-expanded={!collapsed}
          className="min-h-11 shrink-0 rounded-md border border-border-strong px-3 text-[13px] font-semibold text-ink hover:bg-subtle sm:min-h-9"
        >
          {collapsed ? "Show steps" : "Hide steps"}
        </button>
      </div>

      {!collapsed && (
        <ol className="border-t border-border">
          {onboarding.steps.map((s) => (
            <StepRow key={s.key} ownerType={ownerType} step={s} />
          ))}
        </ol>
      )}
    </section>
  );
}

function StepRow({ ownerType, step }: { ownerType: OwnerType; step: OnboardingStep }) {
  const copy = stepCopy(ownerType, step.key);
  const done = step.state === "done";
  const waiting = step.state === "waiting_on_us";

  return (
    <li className="flex items-start gap-3 border-b border-border px-5 py-3 last:border-b-0">
      <span
        aria-hidden="true"
        className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border ${
          done ? "border-success-ink bg-success-surface text-success-ink" : waiting ? "border-warning-ink bg-warning-surface text-warning-ink" : "border-border-strong bg-surface"
        }`}
      >
        {done && <PortalIcon name="check" className="h-3 w-3" />}
      </span>
      <div className="min-w-0 flex-1">
        <p className={`text-sm font-semibold ${done ? "text-ink-muted line-through decoration-ink-subtle" : "text-ink"}`}>
          {copy.title}
          <span className="sr-only">{done ? " (done)" : waiting ? " (waiting on PekanGame)" : step.state === "optional" ? " (optional)" : " (to do)"}</span>
        </p>
        {!done && <p className="mt-0.5 text-xs text-ink-muted">{copy.text}</p>}
        {step.key === "dns" && step.state === "todo" && step.detail && <DnsDeadline iso={step.detail} />}
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {waiting && <Badge tone="warning">Waiting on us</Badge>}
        {step.state === "optional" && <Badge tone="neutral">Optional</Badge>}
        {(step.state === "todo" || step.state === "optional") && copy.href && copy.cta && (
          <Link href={copy.href} className={buttonClass("secondary", true)}>
            {copy.cta}
          </Link>
        )}
      </div>
    </li>
  );
}

/** A domain still waiting on DNS is removed after a fixed time; say when, in Malaysia time. */
function DnsDeadline({ iso }: { iso: string }) {
  const date = new Intl.DateTimeFormat("en-MY", { day: "numeric", month: "short", year: "numeric", timeZone: "Asia/Kuala_Lumpur" }).format(new Date(iso));
  return <p className="mt-1 text-xs font-medium text-warning-ink">Finish by {date}, or the pending domain is removed. You can add it again later.</p>;
}
