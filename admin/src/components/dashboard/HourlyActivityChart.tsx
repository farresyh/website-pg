"use client";

/**
 * DASH-5 — orders per hour for one selected day (not a week aggregate
 * — ADR-045 decision 19). Simple inline-SVG-free CSS bar chart, same
 * no-new-charting-library approach the Reports build already used.
 */

const BAR_COLOR = { light: "#2a78d6", dark: "#3987e5" };

export function HourlyActivityChart({
  hours,
  theme,
}: {
  hours: Array<{ hour: number; count: number }>;
  theme: "light" | "dark";
}) {
  const color = BAR_COLOR[theme];
  const max = Math.max(1, ...hours.map((h) => h.count));

  return (
    <div className="flex h-40 items-end gap-1">
      {hours.map((h) => (
        <div key={h.hour} className="group relative flex flex-1 flex-col items-center justify-end">
          <div
            className="w-full rounded-t-sm transition-opacity group-hover:opacity-80"
            style={{ height: `${Math.max(2, (h.count / max) * 100)}%`, backgroundColor: color }}
          />
          <span className="pointer-events-none absolute -top-6 hidden rounded bg-gray-800 px-1.5 py-0.5 text-[10px] text-white group-hover:block dark:bg-gray-700">
            {h.count}
          </span>
          {h.hour % 3 === 0 && (
            <span className="mt-1 text-[9px] text-gray-400 dark:text-gray-500">{h.hour}</span>
          )}
        </div>
      ))}
    </div>
  );
}
