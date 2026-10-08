"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportTrendDay, getReportTrend } from "@/lib/reports";
import { TrendChart } from "../TrendChart";
import { toRm } from "../format";
import { marginPct } from "@/lib/report-buckets";

export function ProfitAnalysisTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [trend, setTrend] = useState<ReportTrendDay[] | null>(null);

  useEffect(() => {
    // ADR-086 filter-unification follow-up — both charts below share one
    // fetch and now follow the page's own filter (no more a private
    // 7/14/30-day toggle disagreeing with it).
    getReportTrend(token, filters)
      .then((res) => setTrend(res.days))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.from, filters.to, filters.affiliateId]);

  const marginPcts = trend?.map((d) => marginPct(d.platform_profit, d.sales)) ?? [];

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Owner vs Affiliate Profit</h2>
        </div>
        {trend ? (
          <TrendChart
            dates={trend.map((d) => d.date)}
            series1={{ label: "Owner Profit", values: trend.map((d) => toRm(d.platform_profit)), color: "chart-2" }}
            series2={{ label: "Affiliate Profit", values: trend.map((d) => toRm(d.affiliate_profit)), color: "chart-3" }}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
            formatTick={(v) => (Math.abs(v) >= 1000 ? `${(v / 1000).toFixed(1)}k` : Math.abs(v) < 10 ? v.toFixed(2) : v.toFixed(0))}
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
            series1={{ label: "Margin %", values: marginPcts, color: "chart-2" }}
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
