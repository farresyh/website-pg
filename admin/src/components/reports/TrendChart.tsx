"use client";

/**
 * Generic 1- or 2-series daily trend line chart, built per the dataviz
 * skill's procedure (2026-08-26, extended 2026-08-27 for reuse beyond
 * RPT-2's Sales-vs-Profit overlay): hand-rolled inline SVG (no charting
 * library installed), single shared y-axis (never dual-axis), palette
 * validated with the skill's own contrast/CVD checker (categorical slot
 * 1 blue + slot 3 aqua for 2 series; single-hue blue, the skill's
 * documented "sequential is the safe default", for 1 series). The
 * light-mode aqua contrast WARN is why the legend, end-labels, and
 * table view all exist for the 2-series case — required "relief", not
 * decoration.
 */

import { useMemo, useState } from "react";
import { useTheme } from "@/context/ThemeContext";

const COLORS = {
  light: { series1: "#2a78d6", series2: "#1baf7a", grid: "#e1e0d9", axis: "#898781", text: "#52514e" },
  dark: { series1: "#3987e5", series2: "#199e70", grid: "#2c2c2a", axis: "#898781", text: "#c3c2b7" },
};

function niceCeil(value: number): number {
  if (value <= 0) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const normalized = value / magnitude;
  const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
  return step * magnitude;
}

function formatDateLabel(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("en-MY", { day: "numeric", month: "short" });
}

const WIDTH = 720;
const HEIGHT = 260;
const PADDING = { top: 16, right: 64, bottom: 28, left: 56 };

export interface TrendSeries {
  key: string;
  label: string;
}

interface TrendChartProps {
  dates: string[];
  series1: { label: string; values: number[] };
  series2?: { label: string; values: number[] };
  formatValue: (value: number) => string;
  formatTick: (value: number) => string;
}

export function TrendChart({ dates, series1, series2, formatValue, formatTick }: TrendChartProps) {
  const { theme } = useTheme();
  const colors = COLORS[theme];
  const [hoverIndex, setHoverIndex] = useState<number | null>(null);
  const [showTable, setShowTable] = useState(false);

  const plotWidth = WIDTH - PADDING.left - PADDING.right;
  const plotHeight = HEIGHT - PADDING.top - PADDING.bottom;

  const allValues = series2 ? [...series1.values, ...series2.values] : series1.values;
  const maxValue = niceCeil(Math.max(1, ...allValues));

  const xFor = (index: number) => (dates.length <= 1 ? 0 : (index / (dates.length - 1)) * plotWidth);
  const yFor = (value: number) => plotHeight - (value / maxValue) * plotHeight;

  const path1 = useMemo(
    () => series1.values.map((v, i) => `${i === 0 ? "M" : "L"}${xFor(i)},${yFor(v)}`).join(" "),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [dates, maxValue, series1.values],
  );
  const path2 = useMemo(
    () => series2?.values.map((v, i) => `${i === 0 ? "M" : "L"}${xFor(i)},${yFor(v)}`).join(" ") ?? null,
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [dates, maxValue, series2?.values],
  );

  const yTicks = [0, 0.25, 0.5, 0.75, 1].map((f) => maxValue * f);
  const xTickEvery = dates.length > 14 ? 5 : dates.length > 7 ? 2 : 1;
  const hasData = allValues.some((v) => v > 0);

  if (!hasData) {
    return <p className="py-16 text-center text-sm text-gray-500 dark:text-gray-400">No paid orders in this range yet.</p>;
  }

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        {series2 && (
          <div className="flex items-center gap-4 text-theme-xs text-gray-500 dark:text-gray-400">
            <span className="flex items-center gap-1.5">
              <span className="inline-block h-0.5 w-3.5 rounded-full" style={{ backgroundColor: colors.series1 }} />
              {series1.label}
            </span>
            <span className="flex items-center gap-1.5">
              <span className="inline-block h-0.5 w-3.5 rounded-full" style={{ backgroundColor: colors.series2 }} />
              {series2.label}
            </span>
          </div>
        )}
        <button
          type="button"
          onClick={() => setShowTable((v) => !v)}
          className="ml-auto text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
        >
          {showTable ? "View chart" : "View as table"}
        </button>
      </div>

      {showTable ? (
        <div className="max-h-72 overflow-auto rounded-lg border border-gray-200 dark:border-gray-800">
          <table className="w-full text-theme-sm">
            <thead>
              <tr className="border-b border-gray-200 text-left text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <th className="px-3 py-2 font-medium">Date</th>
                <th className="px-3 py-2 font-medium">{series1.label}</th>
                {series2 && <th className="px-3 py-2 font-medium">{series2.label}</th>}
              </tr>
            </thead>
            <tbody>
              {dates.map((date, i) => (
                <tr key={date} className="border-b border-gray-100 last:border-0 dark:border-gray-800/60">
                  <td className="px-3 py-2 text-gray-600 dark:text-gray-300">{formatDateLabel(date)}</td>
                  <td className="px-3 py-2 tabular-nums text-gray-800 dark:text-white/90">{formatValue(series1.values[i])}</td>
                  {series2 && (
                    <td className="px-3 py-2 tabular-nums text-gray-800 dark:text-white/90">{formatValue(series2.values[i])}</td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="relative">
          <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="w-full" role="img" aria-label="Daily trend">
            <g transform={`translate(${PADDING.left},${PADDING.top})`}>
              {yTicks.map((tick) => (
                <g key={tick}>
                  <line x1={0} x2={plotWidth} y1={yFor(tick)} y2={yFor(tick)} stroke={colors.grid} strokeWidth={1} />
                  <text x={-8} y={yFor(tick)} textAnchor="end" dominantBaseline="middle" fontSize={10} fill={colors.axis}>
                    {formatTick(tick)}
                  </text>
                </g>
              ))}

              {dates.map((date, i) =>
                i % xTickEvery === 0 ? (
                  <text key={date} x={xFor(i)} y={plotHeight + 18} textAnchor="middle" fontSize={10} fill={colors.axis}>
                    {formatDateLabel(date)}
                  </text>
                ) : null,
              )}

              <path d={path1} fill="none" stroke={colors.series1} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
              {path2 && (
                <path d={path2} fill="none" stroke={colors.series2} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
              )}

              {dates.length > 0 && (
                <>
                  <text
                    x={xFor(dates.length - 1) + 6}
                    y={yFor(series1.values[series1.values.length - 1])}
                    fontSize={10}
                    fontWeight={600}
                    dominantBaseline="middle"
                    fill={colors.text}
                  >
                    {formatValue(series1.values[series1.values.length - 1])}
                  </text>
                  {series2 && (
                    <text
                      x={xFor(dates.length - 1) + 6}
                      y={yFor(series2.values[series2.values.length - 1]) + 12}
                      fontSize={10}
                      fontWeight={600}
                      dominantBaseline="middle"
                      fill={colors.text}
                    >
                      {formatValue(series2.values[series2.values.length - 1])}
                    </text>
                  )}
                </>
              )}

              {hoverIndex !== null && (
                <>
                  <line x1={xFor(hoverIndex)} x2={xFor(hoverIndex)} y1={0} y2={plotHeight} stroke={colors.axis} strokeWidth={1} />
                  <circle
                    cx={xFor(hoverIndex)}
                    cy={yFor(series1.values[hoverIndex])}
                    r={4}
                    fill={colors.series1}
                    stroke={theme === "dark" ? "#1a1a19" : "#fcfcfb"}
                    strokeWidth={2}
                  />
                  {series2 && (
                    <circle
                      cx={xFor(hoverIndex)}
                      cy={yFor(series2.values[hoverIndex])}
                      r={4}
                      fill={colors.series2}
                      stroke={theme === "dark" ? "#1a1a19" : "#fcfcfb"}
                      strokeWidth={2}
                    />
                  )}
                </>
              )}

              <rect
                x={0}
                y={0}
                width={plotWidth}
                height={plotHeight}
                fill="transparent"
                onMouseMove={(e) => {
                  const rect = e.currentTarget.getBoundingClientRect();
                  const relativeX = e.clientX - rect.left;
                  const index = Math.round((relativeX / plotWidth) * (dates.length - 1));
                  setHoverIndex(Math.min(dates.length - 1, Math.max(0, index)));
                }}
                onMouseLeave={() => setHoverIndex(null)}
              />
            </g>
          </svg>

          {hoverIndex !== null && (
            <div
              className="pointer-events-none absolute top-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-xs shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
              style={{ left: `${Math.min(85, Math.max(5, (xFor(hoverIndex) / plotWidth) * 100))}%` }}
            >
              <p className="mb-1 font-medium text-gray-500 dark:text-gray-400">{formatDateLabel(dates[hoverIndex])}</p>
              <p className="flex items-center justify-between gap-4">
                <span className="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                  <span className="inline-block h-0.5 w-3 rounded-full" style={{ backgroundColor: colors.series1 }} />
                  {series1.label}
                </span>
                <span className="tabular-nums font-semibold text-gray-800 dark:text-white/90">
                  {formatValue(series1.values[hoverIndex])}
                </span>
              </p>
              {series2 && (
                <p className="flex items-center justify-between gap-4">
                  <span className="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                    <span className="inline-block h-0.5 w-3 rounded-full" style={{ backgroundColor: colors.series2 }} />
                    {series2.label}
                  </span>
                  <span className="tabular-nums font-semibold text-gray-800 dark:text-white/90">
                    {formatValue(series2.values[hoverIndex])}
                  </span>
                </p>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
