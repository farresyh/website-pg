"use client";

import { useEffect, useState } from "react";
import {
  type ReportFilters,
  type ReportSummary,
  type ReportTrendDay,
  type ReportGameRow,
  type ReportDailyBreakdownRow,
  getReportSummary,
  getReportTrend,
  getTopGames,
  getReportDailyBreakdown,
} from "@/lib/reports";
import { ApiError } from "@/lib/api-client";
import { StatCard } from "../StatCard";
import { TrendChart } from "../TrendChart";
import { HorizontalBarList } from "../HorizontalBarList";
import { DailyBreakdownTable } from "../DailyBreakdownTable";
import { formatRm, toRm } from "../format";
import { ChartLine as ChartLineIcon, ChartLine as TrendUpIcon } from "@primeicons/react/chart-line";
import { List as ListIcon } from "@primeicons/react/list";
import { User as UserCircleIcon } from "@primeicons/react/user";
import { Tag as TagIcon } from "@primeicons/react/tag";

function timeAgo(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime();
  const minutes = Math.floor(diffMs / 60000);
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

export function OverviewTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [summary, setSummary] = useState<ReportSummary | null>(null);
  const [trend, setTrend] = useState<ReportTrendDay[] | null>(null);
  const [topGames, setTopGames] = useState<ReportGameRow[] | null>(null);
  const [dailyRows, setDailyRows] = useState<ReportDailyBreakdownRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getReportSummary(token, filters)
      .then(setSummary)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load report summary."));
    getTopGames(token, filters, 5)
      .then((res) => setTopGames(res.games))
      .catch(() => undefined);
    getReportDailyBreakdown(token, filters)
      .then((res) => setDailyRows(res.days))
      .catch(() => undefined);
    // ADR-086 filter-unification follow-up — the trend chart now follows
    // this same filter (no more its own private 7/14/30-day toggle).
    getReportTrend(token, filters)
      .then((res) => setTrend(res.days))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the sales trend."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.from, filters.to, filters.affiliateId]);

  return (
    <div>
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard icon={<ChartLineIcon width={18} height={18} />} color="blue" label="Total Sales" value={summary ? formatRm(summary.total_sales) : "—"} />
        <StatCard icon={<ListIcon width={18} height={18} />} color="indigo" label="Total Orders" value={summary ? summary.orders_count.toLocaleString() : "—"} />
        <StatCard icon={<TrendUpIcon width={18} height={18} />} color="green" label="Owner Profit" value={summary ? formatRm(summary.platform_profit) : "—"} sub={summary ? `${summary.margin_pct.toFixed(2)}% margin` : undefined} />
        <StatCard icon={<UserCircleIcon width={18} height={18} />} color="violet" label="Affiliate Profit" value={summary ? formatRm(summary.affiliate_profit) : "—"} />
        <StatCard icon={<TagIcon width={18} height={18} />} color="amber" label="Avg Order Value" value={summary ? formatRm(summary.avg_order_value) : "—"} />
      </div>

      {summary?.latest_order && (
        <p className="mb-6 text-theme-xs text-gray-500 dark:text-gray-400">
          Latest paid order: <span className="font-medium text-gray-700 dark:text-gray-200">{summary.latest_order.order_number}</span>
          {" — "}
          {formatRm(summary.latest_order.final_amount)}, {timeAgo(summary.latest_order.paid_at)}
        </p>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Sales vs Owner Profit</h2>
          </div>
          {trend ? (
            <TrendChart
              dates={trend.map((d) => d.date)}
              series1={{ label: "Sales", values: trend.map((d) => toRm(d.sales)) }}
              series2={{ label: "Owner Profit", values: trend.map((d) => toRm(d.platform_profit)) }}
              formatValue={(v) => `RM ${v.toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
              formatTick={(v) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : v < 10 ? v.toFixed(2) : v.toFixed(0))}
            />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Top Performing Games</h2>
          {topGames ? (
            <HorizontalBarList
              items={topGames.map((g) => ({ label: g.game_name, value: toRm(g.sales), sublabel: `${g.orders_count} orders` }))}
              formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
            />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Detailed Data</h2>
        {dailyRows ? <DailyBreakdownTable rows={dailyRows} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
      </div>
    </div>
  );
}
