"use client";

/**
 * ADR-139 decisions 2, 3: one ladder version as the panel shows it, steps as
 * rows and tiers as columns. Each cell is the cumulative reward; the amount
 * a reseller sees for that step (the increment) is underneath.
 */

import type { ReactNode } from "react";
import type { RebateLadder, RebateTier } from "@/lib/rebate";
import { RebateTable, TD_STRONG } from "@/components/rebate/RebateTable";

export const rebateRm = (sen: number) => `RM ${(sen / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export function RebateLadderGrid({ title, ladder, tiers }: { title: ReactNode; ladder: RebateLadder; tiers: RebateTier[] }) {
  const columns = tiers.filter((t) => ladder.steps[0]?.rewards[String(t.id)] !== undefined);
  const rows = ladder.steps.map((step, i) => ({ id: i, step, previous: i === 0 ? null : ladder.steps[i - 1] }));

  return (
    <RebateTable
      title={title}
      headers={[
        "Monthly sales reached",
        ...columns.map((t) => (
          <span key={t.id}>
            {t.name} <span className="text-ink-subtle">(+{Number(t.markup_percent)}%)</span>
          </span>
        )),
      ]}
      rows={rows}
      empty="No steps: the rebate is off from this month."
      cells={({ step, previous }) => [
        [TD_STRONG, rebateRm(step.threshold_sen)],
        ...columns.map((t): [string, ReactNode] => {
          const cumulative = step.rewards[String(t.id)] ?? 0;
          return [
            "px-5 py-4 font-mono text-theme-sm text-ink",
            <>
              {rebateRm(cumulative)}
              <span className="block font-sans text-theme-xs text-ink-subtle">+{rebateRm(cumulative - (previous?.rewards[String(t.id)] ?? 0))} this step</span>
            </>,
          ];
        }),
      ]}
    />
  );
}
