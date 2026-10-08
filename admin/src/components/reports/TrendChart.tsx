"use client";

/**
 * 1- or 2-series trend line chart. ADR-104 R1: Recharts, replacing the
 * hand-rolled SVG (reverses ADR-086's PR-3 closure). Series colours are
 * the artifact's chart tokens (`chart-1` sales/orders, `chart-2` owner
 * profit/margin, `chart-3` affiliate profit), resolved through CSS vars
 * so light/dark follow the theme with no JS. One y-axis, never two.
 *
 * The dataviz validator passes the palette on CVD and normal-vision
 * separation and contrast, but flags the muted brand hues on chroma; so
 * identity never rests on colour alone: legend + end labels + a table view.
 */

import { useState } from "react";
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { TrendBucket } from "@/lib/report-buckets";

export type ChartColor = "chart-1" | "chart-2" | "chart-3";

export interface TrendSeriesInput {
  label: string;
  values: (number | null)[];
  color?: ChartColor;
}

interface TrendChartProps {
  dates: string[];
  series1: TrendSeriesInput;
  series2?: TrendSeriesInput;
  formatValue: (value: number) => string;
  formatTick: (value: number) => string;
  /** Labels each point as a day, the week starting that day, or a month. */
  bucket?: TrendBucket;
}

function formatDateLabel(iso: string, bucket: TrendBucket): string {
  const d = new Date(`${iso}T00:00:00`);
  if (bucket === "month") return d.toLocaleDateString("en-MY", { month: "short", year: "numeric" });
  const day = d.toLocaleDateString("en-MY", { day: "numeric", month: "short" });
  return bucket === "week" ? `Week of ${day}` : day;
}

function shortDateLabel(iso: string, bucket: TrendBucket): string {
  const d = new Date(`${iso}T00:00:00`);
  return bucket === "month"
    ? d.toLocaleDateString("en-MY", { month: "short", year: "2-digit" })
    : d.toLocaleDateString("en-MY", { day: "numeric", month: "short" });
}

const colorVar = (color: ChartColor) => `var(--color-${color})`;

export function TrendChart({ dates, series1, series2, formatValue, formatTick, bucket = "day" }: TrendChartProps) {
  const [showTable, setShowTable] = useState(false);

  const series: (TrendSeriesInput & { key: "s1" | "s2"; color: ChartColor })[] = [
    { key: "s1", ...series1, color: series1.color ?? "chart-1" },
    ...(series2 ? [{ key: "s2" as const, ...series2, color: series2.color ?? "chart-2" }] : []),
  ];

  const data = dates.map((date, i) => ({ date, s1: series1.values[i], s2: series2?.values[i] ?? null }));
  const hasData = series.some((s) => s.values.some((v) => v !== null && v !== 0));

  if (!hasData) {
    return <p className="py-16 text-center text-sm text-ink-muted">No paid orders in this range yet.</p>;
  }

  const lastIndex = dates.length - 1;

  return (
    <div>
      <div className="mb-3 flex items-center justify-between gap-4">
        {series.length > 1 && (
          <div className="flex items-center gap-4 text-theme-xs text-ink-muted">
            {series.map((s) => (
              <span key={s.key} className="flex items-center gap-1.5">
                <span className="inline-block h-0.5 w-3.5 rounded-full" style={{ backgroundColor: colorVar(s.color) }} />
                {s.label}
              </span>
            ))}
          </div>
        )}
        <button
          type="button"
          onClick={() => setShowTable((v) => !v)}
          className="ml-auto text-theme-xs font-medium text-cyan-ink hover:underline"
        >
          {showTable ? "View chart" : "View as table"}
        </button>
      </div>

      {showTable ? (
        <div className="max-h-72 overflow-auto rounded-lg border border-border">
          <table className="w-full text-theme-sm">
            <thead>
              <tr className="border-b border-border text-left text-theme-xs text-ink-muted">
                <th className="px-3 py-2 font-medium">{bucket === "month" ? "Month" : bucket === "week" ? "Week" : "Date"}</th>
                {series.map((s) => (
                  <th key={s.key} className="px-3 py-2 text-right font-medium">
                    {s.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.date} className="border-b border-border last:border-0">
                  <td className="px-3 py-2 text-ink-muted">{formatDateLabel(row.date, bucket)}</td>
                  {series.map((s) => {
                    const v = row[s.key];
                    return (
                      <td key={s.key} className="px-3 py-2 text-right tabular-nums text-ink">
                        {v === null || v === undefined ? "—" : formatValue(v)}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="h-[260px] w-full" role="img" aria-label={series.map((s) => s.label).join(" and ") + " trend"}>
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 12, right: 72, bottom: 0, left: 0 }}>
              <CartesianGrid vertical={false} stroke="var(--color-chart-grid)" strokeWidth={1} />
              <XAxis
                dataKey="date"
                tickFormatter={(d: string) => shortDateLabel(d, bucket)}
                tick={{ fontSize: 11, fill: "var(--color-ink-muted)" }}
                tickLine={false}
                axisLine={{ stroke: "var(--color-chart-grid)" }}
                minTickGap={24}
              />
              <YAxis
                tickFormatter={formatTick}
                tick={{ fontSize: 11, fill: "var(--color-ink-muted)" }}
                tickLine={false}
                axisLine={false}
                width={56}
              />
              <Tooltip
                cursor={{ stroke: "var(--color-border-control)", strokeWidth: 1 }}
                content={({ active, label }) => {
                  if (!active || typeof label !== "string") return null;
                  const row = data.find((d) => d.date === label);
                  if (!row) return null;
                  return (
                    <div className="rounded-lg border border-border bg-surface px-3 py-2 text-theme-xs shadow-md">
                      <p className="mb-1 font-medium text-ink-muted">{formatDateLabel(label, bucket)}</p>
                      {series.map((s) => {
                        const v = row[s.key];
                        return (
                          <p key={s.key} className="flex items-center justify-between gap-4">
                            <span className="flex items-center gap-1.5 text-ink-muted">
                              <span className="inline-block h-0.5 w-3 rounded-full" style={{ backgroundColor: colorVar(s.color) }} />
                              {s.label}
                            </span>
                            <span className="font-semibold tabular-nums text-ink">
                              {v === null || v === undefined ? "—" : formatValue(v)}
                            </span>
                          </p>
                        );
                      })}
                    </div>
                  );
                }}
              />
              {series.map((s, si) => (
                <Line
                  key={s.key}
                  dataKey={s.key}
                  name={s.label}
                  type="linear"
                  stroke={colorVar(s.color)}
                  strokeWidth={2}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  dot={false}
                  activeDot={{ r: 4, fill: colorVar(s.color), stroke: "var(--color-surface)", strokeWidth: 2 }}
                  connectNulls={false}
                  isAnimationActive={false}
                  label={(props: { x?: number | string; y?: number | string; index?: number; value?: unknown }) =>
                    props.index === lastIndex && typeof props.value === "number" ? (
                      <text
                        key={`end-${s.key}`}
                        x={Number(props.x) + 8}
                        y={Number(props.y) + si * 12}
                        fontSize={11}
                        fontWeight={600}
                        dominantBaseline="middle"
                        fill="var(--color-ink)"
                      >
                        {formatValue(props.value)}
                      </text>
                    ) : (
                      <g key={`end-${s.key}-${props.index}`} />
                    )
                  }
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  );
}
