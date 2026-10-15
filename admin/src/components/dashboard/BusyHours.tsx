"use client";

/**
 * ADR-104 2026-10-15 — paid orders by KL hour of payment, summed over the
 * Dashboard's period (`/reports/busy-hours`). The busiest 3-hour window is
 * drawn at full strength and named under the chart; the rest are lighter.
 */

import type { ReportBusyHour } from "@/lib/reports";
import { busyWindows, formatHourWindow } from "@/lib/dashboard-briefing";

const AXIS = [0, 3, 6, 9, 12, 15, 18, 21];

function axisLabel(hour: number): string {
  return hour === 0 ? "12 AM" : hour === 12 ? "12 PM" : hour < 12 ? `${hour} AM` : `${hour - 12} PM`;
}

export function BusyHours({ hours, emptyText }: { hours: ReportBusyHour[]; emptyText: string }) {
  const counts = hours.map((h) => h.orders_count);
  const windows = busyWindows(counts);
  const total = counts.reduce((a, b) => a + b, 0);

  if (!windows) {
    return <p className="py-14 text-center text-sm text-ink-muted">{emptyText}</p>;
  }

  const max = Math.max(...counts);
  const inPeak = (hour: number) => (hour - windows.peak.start + 24) % 24 < 3;
  const peakLabel = formatHourWindow(windows.peak.start, 3);

  return (
    <figure className="m-0">
      <div
        role="img"
        aria-label={`Paid orders by hour. Busiest ${peakLabel}: ${windows.peak.orders} of ${total} orders.`}
        className="flex h-40 items-end gap-[3px] border-b border-border-strong"
      >
        {hours.map((h) => (
          <div
            key={h.hour}
            title={`${String(h.hour).padStart(2, "0")}:00–${String(h.hour).padStart(2, "0")}:59 · ${h.orders_count} ${h.orders_count === 1 ? "order" : "orders"}`}
            className={`flex-1 rounded-t-[2px] bg-chart-1 ${inPeak(h.hour) ? "" : "opacity-40"}`}
            style={{ height: h.orders_count === 0 ? 0 : `${Math.max(3, (h.orders_count / max) * 100)}%` }}
          />
        ))}
      </div>
      <div aria-hidden className="relative mt-1.5 h-4 text-[11px] text-ink-subtle">
        {AXIS.map((hour) => (
          <span key={hour} className="absolute -translate-x-1/2 whitespace-nowrap max-sm:[&:nth-child(even)]:hidden" style={{ left: `${((hour + 0.5) / 24) * 100}%` }}>
            {axisLabel(hour)}
          </span>
        ))}
      </div>
      <figcaption className="mt-4 flex flex-wrap justify-between gap-2 border-t border-border pt-3 text-theme-xs text-ink-muted">
        <span>
          <b className="font-semibold text-ink">Peak {peakLabel}</b> · {windows.peak.orders} of {total} {total === 1 ? "order" : "orders"} ({windows.peak.pct}%)
        </span>
        <span>Quietest {formatHourWindow(windows.quiet.start, 3)}</span>
      </figcaption>
    </figure>
  );
}
