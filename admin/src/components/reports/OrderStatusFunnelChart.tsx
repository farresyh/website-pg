"use client";

/**
 * Orders tab — delivery-status funnel. Uses the dataviz skill's fixed
 * status palette (good/warning/serious/critical — never themed, never
 * reused for series identity elsewhere on this page): delivered=good,
 * in-flight states=warning, needs_review=serious, failed=critical.
 */

import type { ReportOrderStatusFunnel } from "@/lib/reports";

const STATUS_COLOR = {
  good: "#0ca30c",
  warning: "#fab219",
  serious: "#ec835a",
  critical: "#d03b3b",
};

const STATUS_META: Array<{
  key: keyof ReportOrderStatusFunnel["by_status"];
  label: string;
  color: string;
}> = [
  { key: "delivered", label: "Delivered", color: STATUS_COLOR.good },
  { key: "processing", label: "Processing", color: STATUS_COLOR.warning },
  { key: "pending", label: "Pending (supplier)", color: STATUS_COLOR.warning },
  { key: "not_started", label: "Not Started", color: STATUS_COLOR.warning },
  { key: "needs_review", label: "Needs Review", color: STATUS_COLOR.serious },
  { key: "failed", label: "Failed", color: STATUS_COLOR.critical },
];

export function OrderStatusFunnelChart({ funnel }: { funnel: ReportOrderStatusFunnel }) {
  if (funnel.total === 0) {
    return <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No paid orders in this range yet.</p>;
  }

  return (
    <ul className="space-y-3">
      {STATUS_META.map(({ key, label, color }) => {
        const count = funnel.by_status[key];
        const pct = (count / funnel.total) * 100;

        return (
          <li key={key}>
            <div className="mb-1 flex items-baseline justify-between gap-3 text-theme-sm">
              <span className="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-200">
                <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} />
                {label}
              </span>
              <span className="tabular-nums text-gray-800 dark:text-white/90">
                {count.toLocaleString()} <span className="text-gray-400 dark:text-gray-500">({pct.toFixed(1)}%)</span>
              </span>
            </div>
            <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-white/[0.06]">
              <div className="h-2 rounded-full" style={{ width: `${Math.max(pct, count > 0 ? 2 : 0)}%`, backgroundColor: color }} />
            </div>
          </li>
        );
      })}
    </ul>
  );
}
