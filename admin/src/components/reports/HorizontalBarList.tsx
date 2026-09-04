"use client";

/**
 * Ranked magnitude comparison (Top Games, Payment Method / Affiliate
 * share) — single-hue bars per the dataviz skill's default ("sequential
 * is the safe default... unless the job is specifically identity or
 * polarity" — this is plain magnitude ranking, not series identity).
 * Value labeled at the bar's end (marks-and-anatomy.md: "Bars -> value
 * at the tip").
 */

const BAR_COLOR = { light: "#2a78d6", dark: "#3987e5" };

import { useTheme } from "@/context/ThemeContext";

export interface HorizontalBarItem {
  label: string;
  value: number;
  sublabel?: string;
}

export function HorizontalBarList({
  items,
  formatValue,
}: {
  items: HorizontalBarItem[];
  formatValue: (value: number) => string;
}) {
  const { theme } = useTheme();
  const color = BAR_COLOR[theme];
  const max = Math.max(1, ...items.map((i) => i.value));

  if (items.length === 0) {
    return <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No data in this range yet.</p>;
  }

  return (
    <ul className="space-y-3">
      {items.map((item) => (
        <li key={item.label}>
          <div className="mb-1 flex items-baseline justify-between gap-3 text-theme-sm">
            <span className="truncate font-medium text-gray-700 dark:text-gray-200">{item.label}</span>
            <span className="shrink-0 tabular-nums text-gray-800 dark:text-white/90">{formatValue(item.value)}</span>
          </div>
          <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-white/[0.06]">
            <div
              className="h-2 rounded-full"
              style={{ width: `${Math.max(2, (item.value / max) * 100)}%`, backgroundColor: color }}
            />
          </div>
          {item.sublabel && <p className="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">{item.sublabel}</p>}
        </li>
      ))}
    </ul>
  );
}
