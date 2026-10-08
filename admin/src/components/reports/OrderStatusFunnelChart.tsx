"use client";

/**
 * Orders tab — delivery-status breakdown. ADR-104 R1: Recharts, with the
 * artifact's status tokens (delivered = chart-positive, in flight =
 * warning-ink, needs review / partial = review-ink, failed =
 * chart-negative). Status colour is reserved for delivery health and
 * always travels with its label and count, never colour alone.
 */

import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { ReportOrderStatusFunnel } from "@/lib/reports";

const STATUS_META: { key: keyof ReportOrderStatusFunnel["by_status"]; label: string; color: string }[] = [
  { key: "delivered", label: "Delivered", color: "var(--color-chart-positive)" },
  { key: "processing", label: "Processing", color: "var(--color-warning-ink)" },
  { key: "pending", label: "Pending (supplier)", color: "var(--color-warning-ink)" },
  { key: "not_started", label: "Not started", color: "var(--color-warning-ink)" },
  { key: "needs_review", label: "Needs review", color: "var(--color-review-ink)" },
  { key: "partially_delivered", label: "Partially delivered", color: "var(--color-review-ink)" },
  { key: "failed", label: "Failed", color: "var(--color-chart-negative)" },
];

const ROW_HEIGHT = 36;

export function OrderStatusFunnelChart({ funnel }: { funnel: ReportOrderStatusFunnel }) {
  if (funnel.total === 0) {
    return <p className="py-8 text-center text-sm text-ink-muted">No paid orders in this range yet.</p>;
  }

  const data = STATUS_META.map((s) => {
    const count = funnel.by_status[s.key];
    const pct = (count / funnel.total) * 100;
    // Count on its own right-hand category axis: every row, zero included, keeps its number.
    return { ...s, count, pct, countLabel: `${count.toLocaleString()} (${pct.toFixed(1)}%)` };
  });

  return (
    <div style={{ height: data.length * ROW_HEIGHT }} role="img" aria-label="Orders by delivery status">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} layout="vertical" margin={{ top: 0, right: 0, bottom: 0, left: 0 }} barSize={12}>
          <XAxis type="number" hide domain={[0, funnel.total]} />
          <YAxis
            yAxisId="label"
            type="category"
            dataKey="label"
            width={140}
            tickLine={false}
            axisLine={false}
            tick={{ fontSize: 13, fill: "var(--color-ink)" }}
          />
          <YAxis
            yAxisId="count"
            orientation="right"
            type="category"
            dataKey="countLabel"
            width={96}
            tickLine={false}
            axisLine={false}
            tick={{ fontSize: 12, fill: "var(--color-ink-muted)" }}
          />
          <Tooltip
            cursor={{ fill: "var(--color-overlay)" }}
            content={({ active, payload }) => {
              const row = active ? payload?.[0]?.payload : null;
              if (!row) return null;
              return (
                <div className="rounded-lg border border-border bg-surface px-3 py-2 text-theme-xs shadow-md">
                  <p className="font-semibold tabular-nums text-ink">
                    {row.count.toLocaleString()} <span className="font-normal text-ink-muted">({row.pct.toFixed(1)}%)</span>
                  </p>
                  <p className="text-ink-muted">{row.label}</p>
                </div>
              );
            }}
          />
          <Bar
            yAxisId="label"
            dataKey="count"
            radius={[0, 4, 4, 0]}
            background={{ fill: "var(--color-chart-grid)", radius: 4 }}
            isAnimationActive={false}
          >
            {data.map((row) => (
              <Cell key={row.key} fill={row.color} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
