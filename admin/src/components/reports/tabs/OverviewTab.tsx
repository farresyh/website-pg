"use client";

import { useEffect, useState } from "react";
import {
  type ReportFilters,
  type ReportSummary,
  type ReportTrendDay,
  type ReportGameRow,
  type ReportDailyBreakdownRow,
  type CompareMode,
  type ReportOrderStatusFunnel,
  getOrderStatusFunnel,
  getReportSummary,
  getReportTrend,
  getTopGames,
  getReportDailyBreakdown,
} from "@/lib/reports";
import Link from "next/link";
import { ApiError } from "@/lib/api-client";
import { type TrendBucket, autoBucket, bucketRows } from "@/lib/report-buckets";
import { KpiCard, ReportCard, SegmentedControl } from "../ReportKit";
import { TrendChart } from "../TrendChart";
import { HorizontalBarList } from "../HorizontalBarList";
import { DailyBreakdownTable } from "../DailyBreakdownTable";
import { formatRm, formatRmTick, formatRmValue, formatShortRange, toRm } from "../format";

const BUCKETS: { value: TrendBucket; label: string }[] = [
  { value: "day", label: "Daily" },
  { value: "week", label: "Weekly" },
  { value: "month", label: "Monthly" },
];

function timeAgo(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime();
  const minutes = Math.floor(diffMs / 60000);
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

export function OverviewTab({
  token,
  filters,
  compare,
  rangeIncludesToday,
  onOpenTab,
}: {
  token: string;
  filters: ReportFilters;
  compare?: CompareMode;
  rangeIncludesToday: boolean;
  onOpenTab: (tab: string) => void;
}) {
  const [summary, setSummary] = useState<ReportSummary | null>(null);
  const [trend, setTrend] = useState<ReportTrendDay[] | null>(null);
  const [topGames, setTopGames] = useState<ReportGameRow[] | null>(null);
  const [dailyRows, setDailyRows] = useState<ReportDailyBreakdownRow[] | null>(null);
  const [funnel, setFunnel] = useState<ReportOrderStatusFunnel | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [measure, setMeasure] = useState<"money" | "orders">("money");
  // ADR-104 R17 — the bucket opens on the range's own size; the toggle overrides it.
  const [bucketChoice, setBucketChoice] = useState<TrendBucket | null>(null);

  useEffect(() => {
    // A filter change refetches; a slower earlier response must not land on top of the newer one.
    let current = true;
    const ifCurrent = <T,>(set: (v: T) => void) => (v: T) => {
      if (current) set(v);
    };
    getReportSummary(token, filters, compare)
      .then(ifCurrent(setSummary))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load report summary."));
    getOrderStatusFunnel(token, filters)
      .then(ifCurrent(setFunnel))
      .catch(() => undefined);
    getTopGames(token, filters, 5)
      .then(ifCurrent((res: { games: ReportGameRow[] }) => setTopGames(res.games)))
      .catch(() => undefined);
    getReportDailyBreakdown(token, filters)
      .then(ifCurrent((res: { days: ReportDailyBreakdownRow[] }) => setDailyRows(res.days)))
      .catch(() => undefined);
    // ADR-086 filter-unification follow-up — the trend chart now follows
    // this same filter (no more its own private 7/14/30-day toggle).
    getReportTrend(token, filters)
      .then(ifCurrent((res: { days: ReportTrendDay[] }) => setTrend(res.days)))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the sales trend."));
    return () => {
      current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.from, filters.to, filters.affiliateId, compare]);

  const cmp = summary?.compare ?? undefined;
  const previousLabel = cmp ? formatShortRange(cmp.previous_range.from, cmp.previous_range.to) : undefined;
  // ADR-104 R13 — profit is credited on delivery, so a range ending today still moves.
  const profitNote = rangeIncludesToday ? "Profit is recognised on delivery" : undefined;

  const bucket = bucketChoice ?? autoBucket(trend?.length ?? 0);
  const buckets = trend ? bucketRows(trend, bucket, ["sales", "platform_profit", "orders_count"]) : [];
  const dates = buckets.map((b) => b.date);

  return (
    <div>
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-3 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
        <KpiCard lead label="Paid sales" value={summary ? formatRm(summary.total_sales) : "—"} sub={summary ? `${summary.orders_count.toLocaleString()} paid orders` : undefined} change={cmp?.changes.total_sales} previousLabel={previousLabel} onClick={() => onOpenTab("sales")} />
        <KpiCard lead label="Owner profit" value={summary ? formatRm(summary.platform_profit) : "—"} sub={summary ? `${summary.margin_pct.toFixed(2)}% margin` : undefined} change={cmp?.changes.platform_profit} previousLabel={previousLabel} note={profitNote} onClick={() => onOpenTab("profit")} />
        <KpiCard label="Paid orders" value={summary ? summary.orders_count.toLocaleString() : "—"} sub={funnel ? `${funnel.by_status.delivered} delivered · ${funnel.by_status.failed} failed` : undefined} change={cmp?.changes.orders_count} previousLabel={previousLabel} onClick={() => onOpenTab("orders")} />
        <KpiCard label="Avg order value" value={summary ? formatRm(summary.avg_order_value) : "—"} sub="Per paid order" change={cmp?.changes.avg_order_value} previousLabel={previousLabel} />
        <KpiCard label="Affiliate profit" value={summary ? formatRm(summary.affiliate_profit) : "—"} sub="Earned by affiliates" change={cmp?.changes.affiliate_profit} previousLabel={previousLabel} onClick={() => onOpenTab("affiliates")} />
      </div>

      {summary?.latest_order && (
        <p className="mb-6 flex flex-wrap items-center gap-x-1.5 text-theme-xs text-ink-muted">
          Latest paid order
          <span className="font-mono text-code-id text-ink">{summary.latest_order.order_number}</span>·{" "}
          {formatRm(summary.latest_order.final_amount)} · {timeAgo(summary.latest_order.paid_at)}
          <Link href={`/admin/orders?order=${summary.latest_order.id}`} className="ml-1 font-medium text-cyan-ink hover:underline">
            Open →
          </Link>
        </p>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <ReportCard
          className="lg:col-span-2"
          title={measure === "money" ? "Revenue & profit" : "Paid orders"}
          subtitle={
            measure === "money" && summary && summary.total_sales > 0
              ? `Profit has its own scale — it is about ${Math.round(summary.margin_pct)}% of revenue.`
              : undefined
          }
          actions={
            <div className="flex flex-wrap gap-2">
              <SegmentedControl
                label="Measure"
                value={measure}
                onChange={setMeasure}
                options={[
                  { value: "money", label: "Revenue & profit" },
                  { value: "orders", label: "Orders" },
                ]}
              />
              <SegmentedControl label="Bucket" value={bucket} onChange={setBucketChoice} options={BUCKETS} />
            </div>
          }
        >
          {trend ? (
            <TrendChart
              dates={dates}
              bucket={bucket}
              panels={
                measure === "money"
                  ? [
                      {
                        title: "Revenue (RM)",
                        kind: "bar",
                        series: [{ label: "Revenue", values: buckets.map((b) => toRm(b.sales)), color: "chart-1" }],
                        formatValue: formatRmValue,
                        formatTick: formatRmTick,
                      },
                      {
                        title: "Owner profit (RM)",
                        kind: "bar",
                        series: [{ label: "Owner profit", values: buckets.map((b) => toRm(b.platform_profit)), color: "chart-2" }],
                        formatValue: formatRmValue,
                        formatTick: formatRmTick,
                      },
                    ]
                  : [
                      {
                        title: "Paid orders",
                        kind: "bar",
                        height: 260,
                        series: [{ label: "Paid orders", values: buckets.map((b) => b.orders_count), color: "chart-1" }],
                        formatValue: (v) => v.toLocaleString(),
                        formatTick: (v) => v.toFixed(0),
                      },
                    ]
              }
            />
          ) : (
            <p className="text-sm text-ink-muted">Loading…</p>
          )}
        </ReportCard>

        <ReportCard title="Top games" subtitle="By paid sales">
          {topGames ? (
            <HorizontalBarList
              items={topGames.map((g) => ({ label: g.game_name, value: toRm(g.sales), sublabel: `${g.orders_count} orders` }))}
              formatValue={formatRmValue}
            />
          ) : (
            <p className="text-sm text-ink-muted">Loading…</p>
          )}
        </ReportCard>
      </div>

      <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Detailed Data</h2>
        {dailyRows ? <DailyBreakdownTable rows={dailyRows} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
      </div>
    </div>
  );
}
