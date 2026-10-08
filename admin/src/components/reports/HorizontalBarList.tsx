"use client";

/**
 * Ranked magnitude comparison (Top games, payment method / channel /
 * pricing-basis share) — single-hue bars per the dataviz skill's default
 * (plain magnitude ranking, not series identity). Value labelled at the
 * bar's end. ADR-104 R1: the hue is a chart token (`chart-1` sales,
 * `chart-2` owner profit); R5: optional rank numbers, a Total line and a
 * footer, as in the mockups.
 */

import type { ReactNode } from "react";

export interface HorizontalBarItem {
  label: string;
  value: number;
  /** Under the bar, left (e.g. "40.5% of sales"). */
  sublabel?: string;
  /** Under the bar, right (e.g. "4 orders"). */
  meta?: string;
  /** Mutes the row, e.g. a game with no profit. */
  muted?: boolean;
}

export function HorizontalBarList({
  items,
  formatValue,
  total,
  ranked = false,
  color = "chart-1",
  footer,
}: {
  items: HorizontalBarItem[];
  formatValue: (value: number) => string;
  /** Shown as a Total line; pass the backend's own total, not a re-sum of rounded rows. */
  total?: number;
  ranked?: boolean;
  color?: "chart-1" | "chart-2";
  footer?: ReactNode;
}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  const bar = color === "chart-2" ? "bg-chart-2" : "bg-chart-1";

  if (items.length === 0) {
    return <p className="py-8 text-center text-sm text-ink-muted">No data in this range yet.</p>;
  }

  return (
    <div>
      <ul className="space-y-4">
        {items.map((item, index) => (
          <li key={item.label}>
            <div className="mb-1.5 flex items-baseline justify-between gap-3 text-theme-sm">
              <span className={`flex min-w-0 items-baseline gap-2 font-medium ${item.muted ? "text-ink-muted" : "text-ink"}`}>
                {ranked && <span className="text-theme-xs font-normal tabular-nums text-ink-muted">{index + 1}</span>}
                <span className="truncate">{item.label}</span>
              </span>
              <span className={`shrink-0 font-semibold tabular-nums ${item.muted ? "text-ink-muted" : "text-ink"}`}>{formatValue(item.value)}</span>
            </div>
            <div className="h-1.5 w-full rounded-full bg-subtle">
              {item.value > 0 && <div className={`h-1.5 rounded-full ${bar}`} style={{ width: `${Math.max(2, (item.value / max) * 100)}%` }} />}
            </div>
            {(item.sublabel || item.meta) && (
              <div className="mt-1 flex justify-between gap-3 text-theme-xs text-ink-muted">
                <span>{item.sublabel}</span>
                <span className="tabular-nums">{item.meta}</span>
              </div>
            )}
          </li>
        ))}
      </ul>
      {total !== undefined && (
        <div className="mt-4 flex items-baseline justify-between gap-3 border-t border-border pt-3 text-theme-sm font-semibold text-ink">
          <span>Total</span>
          <span className="tabular-nums">{formatValue(total)}</span>
        </div>
      )}
      {footer && <div className="-mx-5 -mb-5 mt-5 flex items-center justify-between border-t border-border px-5 py-3 text-theme-xs text-ink-muted">{footer}</div>}
    </div>
  );
}

/** The mockup's footer link ("All games →"), opening another tab. */
export function TabLink({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" onClick={onClick} className="font-medium text-cyan-ink hover:underline focus-visible:outline-2 focus-visible:outline-focus-ring">
      {children} →
    </button>
  );
}
