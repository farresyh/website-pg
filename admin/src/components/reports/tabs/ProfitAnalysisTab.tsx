"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportTrendDay, getReportTrend } from "@/lib/reports";
import { TrendChart } from "../TrendChart";
import { toRm } from "../format";

const dayRangeOptions: Array<7 | 14 | 30> = [7, 14, 30];

export function ProfitAnalysisTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [trend, setTrend] = useState<ReportTrendDay[] | null>(null);
  const [days, setDays] = useState<7 | 14 | 30>(30);

  useEffect(() => {
    getReportTrend(token, days, filters.affiliateId)
      .then((res) => setTrend(res.days))
      .catch(() => undefined);
  }, [token, days, filters.affiliateId]);

  const marginPct = trend?.map((d) => (d.sales > 0 ? (d.platform_profit / d.sales) * 100 : 0)) ?? [];

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Owner vs Affiliate Profit</h2>
          <div className="flex gap-1">
            {dayRangeOptions.map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => setDays(d)}
                className={`rounded-md px-2.5 py-1 text-theme-xs font-medium ${
                  days === d
                    ? "bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400"
                    : "text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05]"
                }`}
              >
                {d}d
              </button>
            ))}
          </div>
        </div>
        {trend ? (
          <TrendChart
            dates={trend.map((d) => d.date)}
            series1={{ label: "Owner Profit", values: trend.map((d) => toRm(d.platform_profit)) }}
            series2={{ label: "Affiliate Profit", values: trend.map((d) => toRm(d.affiliate_profit)) }}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
            formatTick={(v) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : v < 10 ? v.toFixed(2) : v.toFixed(0))}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Margin % Trend</h2>
        <p className="mb-4 text-theme-xs text-gray-400 dark:text-gray-500">
          Recent days can look artificially low — profit is only ledger-recognized once delivery completes, so a
          just-paid order&apos;s margin catches up shortly after.
        </p>
        {trend ? (
          <TrendChart
            dates={trend.map((d) => d.date)}
            series1={{ label: "Margin %", values: marginPct }}
            formatValue={(v) => `${v.toFixed(2)}%`}
            formatTick={(v) => `${v.toFixed(0)}%`}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>
    </div>
  );
}
