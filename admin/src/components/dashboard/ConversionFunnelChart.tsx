"use client";

/**
 * DASH-3 — a real cohort funnel (ADR-045 decisions 12-16): three
 * sequential steps over the SAME creation-window cohort, each bar
 * width relative to the Created step (never to its own max — the
 * whole point is to show shrinkage step over step).
 */

import { InfoTooltip } from "./InfoTooltip";
import type { DashboardFunnel } from "@/lib/dashboard";

const STEP_COLOR = { light: "#2a78d6", dark: "#3987e5" };

export function ConversionFunnelChart({ funnel, theme }: { funnel: DashboardFunnel; theme: "light" | "dark" }) {
  const color = STEP_COLOR[theme];
  const base = Math.max(1, funnel.created.value);

  const steps = [
    { key: "created", label: "Orders Created", metric: funnel.created },
    { key: "payment_confirmed", label: "Payment Confirmed", metric: funnel.payment_confirmed },
    { key: "delivered", label: "Delivered", metric: funnel.delivered },
  ] as const;

  return (
    <div>
      <ul className="space-y-3">
        {steps.map((step) => {
          const pct = (step.metric.value / base) * 100;

          return (
            <li key={step.key}>
              <div className="mb-1 flex items-center justify-between gap-3 text-theme-sm">
                <span className="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-200">
                  {step.label}
                  <InfoTooltip definition={step.metric.definition} />
                </span>
                <span className="tabular-nums text-gray-800 dark:text-white/90">{step.metric.value.toLocaleString()}</span>
              </div>
              <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-white/[0.06]">
                <div
                  className="h-2 rounded-full"
                  style={{ width: `${Math.max(2, pct)}%`, backgroundColor: color }}
                />
              </div>
            </li>
          );
        })}
      </ul>
      <p className="mt-3 text-theme-xs text-gray-400 dark:text-gray-500">
        Recent orders may still be in progress — a low Delivered count near the end of the {funnel.window_days}-day window doesn&apos;t
        necessarily mean a problem.
      </p>
    </div>
  );
}
