"use client";

/**
 * Reports trend chart. ADR-104 R1: Recharts, replacing the hand-rolled
 * SVG (reverses ADR-086's PR-3 closure). Shape follows the artifact's
 * mockup: one or more stacked panels over one shared date axis, each
 * with its own y-scale, so profit (~3–10% of revenue) is never flattened
 * under revenue on a shared axis. One y-axis per panel, never two on one.
 *
 * Colours are the artifact's chart tokens through CSS vars (`chart-1`
 * sales/orders, `chart-2` owner profit/margin, `chart-3` affiliate
 * profit), so light/dark follow the theme. The dataviz validator flags
 * the muted brand hues on chroma (CVD separation and contrast pass), so
 * identity never rests on colour alone: legend, labels and a table view.
 */

import { useState } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  LabelList,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { TrendBucket } from "@/lib/report-buckets";

export type ChartColor = "chart-1" | "chart-2" | "chart-3";

export interface TrendSeries {
  label: string;
  values: (number | null)[];
  color: ChartColor;
}

export interface TrendPanel {
  /** Small axis title above the panel, e.g. "Revenue (RM)". */
  title: string;
  kind: "bar" | "line";
  series: TrendSeries[];
  formatValue: (value: number) => string;
  formatTick: (value: number) => string;
  height?: number;
}

const colorVar = (color: ChartColor) => `var(--color-${color})`;

export function formatBucketLabel(iso: string, bucket: TrendBucket): string {
  const d = new Date(`${iso}T00:00:00`);
  if (bucket === "month") return d.toLocaleDateString("en-MY", { month: "short", year: "numeric" });
  const day = d.toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric" });
  return bucket === "week" ? `Week of ${day}` : day;
}

function tickLabel(iso: string, bucket: TrendBucket): string {
  const d = new Date(`${iso}T00:00:00`);
  return bucket === "month"
    ? d.toLocaleDateString("en-MY", { month: "short", year: "2-digit" })
    : d.toLocaleDateString("en-MY", { day: "numeric", month: "short" });
}

/** Point labels only when sparse enough to read (dataviz: selective direct labels). */
const MAX_POINT_LABELS = 12;

export function TrendChart({
  dates,
  panels,
  bucket,
  emptyText = "No paid orders in this range yet.",
  note,
}: {
  dates: string[];
  panels: TrendPanel[];
  bucket: TrendBucket;
  emptyText?: string;
  /** A line under the legend, e.g. a series that is zero throughout. */
  note?: string;
}) {
  const [showTable, setShowTable] = useState(false);

  const all = panels.flatMap((p, pi) => p.series.map((s, si) => ({ ...s, key: `p${pi}s${si}`, panel: p })));
  const data = dates.map((date, i) => ({
    date,
    ...Object.fromEntries(all.map((s) => [s.key, s.values[i]])),
  })) as ({ date: string } & Record<string, number | null>)[];
  const hasData = all.some((s) => s.values.some((v) => v !== null && v !== 0));

  if (!hasData) {
    return <p className="py-16 text-center text-sm text-ink-muted">{emptyText}</p>;
  }

  const tooltip = ({ active, label }: { active?: boolean; label?: unknown }) => {
    if (!active || typeof label !== "string") return null;
    const row = data.find((d) => d.date === label);
    if (!row) return null;
    return (
      <div className="rounded-lg border border-border bg-surface px-3 py-2 text-theme-xs shadow-md">
        <p className="mb-1 font-semibold text-ink">{formatBucketLabel(label, bucket)}</p>
        {all.map((s) => {
          const v = row[s.key];
          return (
            <p key={s.key} className="flex items-center justify-between gap-6">
              <span className="flex items-center gap-1.5 text-ink-muted">
                <span
                  className={s.panel.kind === "bar" ? "inline-block size-2 rounded-[2px]" : "inline-block h-0.5 w-3 rounded-full"}
                  style={{ backgroundColor: colorVar(s.color) }}
                />
                {s.label}
              </span>
              <span className="font-semibold tabular-nums text-ink">
                {v === null || v === undefined ? "—" : s.panel.formatValue(v)}
              </span>
            </p>
          );
        })}
      </div>
    );
  };

  return (
    <div>
      {showTable ? (
        <div className="max-h-80 overflow-auto rounded-lg border border-border">
          <table className="w-full text-theme-sm">
            <thead>
              <tr className="border-b border-border bg-subtle text-left text-theme-xs text-ink-muted">
                <th className="px-3 py-2 font-medium">{bucket === "month" ? "Month" : bucket === "week" ? "Week" : "Date"}</th>
                {all.map((s) => (
                  <th key={s.key} className="px-3 py-2 text-right font-medium">
                    {s.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.date} className="border-b border-border last:border-0">
                  <td className="px-3 py-2 text-ink-muted">{formatBucketLabel(row.date, bucket)}</td>
                  {all.map((s) => {
                    const v = row[s.key];
                    return (
                      <td key={s.key} className="px-3 py-2 text-right tabular-nums text-ink">
                        {v === null || v === undefined ? "—" : s.panel.formatValue(v)}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div role="img" aria-label={`${all.map((s) => s.label).join(", ")} by ${bucket}`}>
          {panels.map((panel, pi) => {
            const isLast = pi === panels.length - 1;
            const series = all.filter((s) => s.panel === panel);
            const pointCount = series[0]?.values.filter((v) => v !== null).length ?? 0;
            const common = {
              data,
              syncId: "report-trend",
              margin: { top: 16, right: 8, bottom: 0, left: 0 },
            };
            const axes = (
              <>
                <CartesianGrid vertical={false} stroke="var(--color-chart-grid)" strokeWidth={1} />
                <XAxis
                  dataKey="date"
                  hide={!isLast}
                  tickFormatter={(d: string) => tickLabel(d, bucket)}
                  tick={{ fontSize: 11, fill: "var(--color-ink-muted)" }}
                  tickLine={false}
                  axisLine={{ stroke: "var(--color-chart-grid)" }}
                  minTickGap={28}
                />
                <YAxis
                  tickFormatter={panel.formatTick}
                  tick={{ fontSize: 11, fill: "var(--color-ink-muted)" }}
                  tickLine={false}
                  axisLine={false}
                  width={52}
                  domain={panel.kind === "line" ? [(min: number) => Math.min(0, min), "auto"] : undefined}
                />
              </>
            );

            return (
              <div key={panel.title}>
                <p className="pl-1 text-theme-xs font-medium text-ink-muted">{panel.title}</p>
                <div style={{ height: panel.height ?? (pi === 0 ? 200 : 120) }}>
                  <ResponsiveContainer width="100%" height="100%">
                    {panel.kind === "bar" ? (
                      <BarChart {...common} barGap={2} barCategoryGap="20%">
                        {axes}
                        <Tooltip cursor={{ fill: "var(--color-overlay)" }} content={tooltip} />
                        {series.map((s) => (
                          <Bar
                            key={s.key}
                            dataKey={s.key}
                            name={s.label}
                            fill={colorVar(s.color)}
                            maxBarSize={24}
                            radius={[4, 4, 0, 0]}
                            isAnimationActive={false}
                          />
                        ))}
                      </BarChart>
                    ) : (
                      <LineChart {...common}>
                        {axes}
                        <Tooltip cursor={{ stroke: "var(--color-border-control)", strokeWidth: 1 }} content={tooltip} />
                        {series.map((s) => (
                          <Line
                            key={s.key}
                            dataKey={s.key}
                            name={s.label}
                            type="linear"
                            stroke={colorVar(s.color)}
                            strokeWidth={2}
                            connectNulls
                            dot={{ r: 4, fill: "var(--color-surface)", stroke: colorVar(s.color), strokeWidth: 2 }}
                            activeDot={{ r: 5, fill: colorVar(s.color), stroke: "var(--color-surface)", strokeWidth: 2 }}
                            isAnimationActive={false}
                          >
                            {pointCount <= MAX_POINT_LABELS && (
                              <LabelList
                                dataKey={s.key}
                                position="top"
                                offset={10}
                                fontSize={11}
                                fill="var(--color-ink)"
                                formatter={(v: unknown) => (typeof v === "number" ? panel.formatValue(v) : "")}
                              />
                            )}
                          </Line>
                        ))}
                      </LineChart>
                    )}
                  </ResponsiveContainer>
                </div>
              </div>
            );
          })}
        </div>
      )}

      <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-theme-xs text-ink-muted">
          {all.map((s) => (
            <span key={s.key} className="flex items-center gap-1.5">
              <span
                className={s.panel.kind === "bar" ? "inline-block size-2.5 rounded-[2px]" : "inline-block h-0.5 w-3.5 rounded-full"}
                style={{ backgroundColor: colorVar(s.color) }}
              />
              {s.label}
            </span>
          ))}
          {note && <span>{note}</span>}
        </div>
        <button
          type="button"
          onClick={() => setShowTable((v) => !v)}
          className="text-theme-xs font-medium text-cyan-ink hover:underline"
        >
          {showTable ? "View chart" : "View as table"}
        </button>
      </div>
    </div>
  );
}
