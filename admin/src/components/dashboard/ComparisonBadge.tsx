"use client";

/**
 * ADR-045 decision 4 — renders MetricComparison: up = green + %, down
 * = red + %, flat = neutral dash, new = neutral "New" (yesterday's
 * base was 0, a percentage would be infinite/NaN).
 */

import type { MetricComparison } from "@/lib/dashboard";

export function ComparisonBadge({ comparison }: { comparison: MetricComparison }) {
  if (comparison.direction === "new") {
    return <span className="text-theme-xs font-medium text-gray-400 dark:text-gray-500">New</span>;
  }

  if (comparison.direction === "flat") {
    return <span className="text-theme-xs font-medium text-gray-400 dark:text-gray-500">— 0%</span>;
  }

  const isUp = comparison.direction === "up";

  return (
    <span
      className={`inline-flex items-center gap-0.5 text-theme-xs font-medium ${
        isUp ? "text-success-600 dark:text-success-400" : "text-error-600 dark:text-error-400"
      }`}
    >
      <span aria-hidden>{isUp ? "▲" : "▼"}</span>
      {comparison.pct}%
    </span>
  );
}
