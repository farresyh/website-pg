"use client";

/**
 * Profit tab, in the mockup's shape: KPIs, profit + margin trends, and
 * Profit by game. ADR-104 R4 — the mockup's generated "From this
 * period's data" sentences are dropped: a report shows figures, not
 * interpretation.
 */

import { useState } from "react";
import { getGameBreakdown, getReportSummary, getReportTrend } from "@/lib/reports";
import { type TrendBucket, autoBucket, bucketRows, marginPct } from "@/lib/report-buckets";
import { KpiCard, Pending, ReportCard, SegmentedControl, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { TrendChart } from "../TrendChart";
import { HorizontalBarList } from "../HorizontalBarList";
import { formatRm, formatRmTick, formatRmValue, toRm } from "../format";

const BUCKETS: { value: TrendBucket; label: string }[] = [
  { value: "day", label: "Daily" },
  { value: "week", label: "Weekly" },
  { value: "month", label: "Monthly" },
];

export function ProfitTab(props: ReportTabProps) {
  const { token, filters, compare, rangeIncludesToday } = props;
  const summary = useReport(props, () => getReportSummary(token, filters, compare));
  const trend = useReport(props, () => getReportTrend(token, filters));
  const games = useReport(props, () => getGameBreakdown(token, filters));
  const [bucketChoice, setBucketChoice] = useState<TrendBucket | null>(null);

  const s = summary.data;
  const cmp = s?.compare ?? undefined;
  const previousLabel = previousLabelOf(cmp);

  const days = trend.data?.days;
  const bucket = bucketChoice ?? autoBucket(days?.length ?? 0);
  const buckets = days ? bucketRows(days, bucket, ["sales", "platform_profit", "affiliate_profit"]) : [];
  const dates = buckets.map((b) => b.date);
  const affiliateAllZero = buckets.every((b) => b.affiliate_profit === 0);

  const byProfit = games.data ? [...games.data.games].sort((a, b) => b.platform_profit - a.platform_profit) : null;

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard lead label="Owner profit" value={s ? formatRm(s.platform_profit) : "—"} sub={rangeIncludesToday ? "Recognised on delivery, so today can still move" : "Recognised on delivered orders"} change={cmp?.changes.platform_profit} previousLabel={previousLabel} />
        <KpiCard label="Margin" value={s ? `${s.margin_pct.toFixed(2)}%` : "—"} sub="Owner profit ÷ paid sales" change={cmp?.changes.margin_pct} previousLabel={previousLabel} />
        <KpiCard label="Affiliate profit" value={s ? formatRm(s.affiliate_profit) : "—"} sub="Earned by affiliates" change={cmp?.changes.affiliate_profit} previousLabel={previousLabel} />
        <KpiCard
          label="Profit per order"
          value={s && s.orders_count > 0 ? formatRm(Math.round(s.platform_profit / s.orders_count)) : "—"}
          sub={s ? `${formatRm(s.platform_profit)} ÷ ${s.orders_count} paid orders` : undefined}
        />
      </div>
      {summary.error && <Pending error={summary.error} className="-mt-3 mb-6" />}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          <ReportCard title="Profit trend" actions={<SegmentedControl label="Bucket" value={bucket} onChange={setBucketChoice} options={BUCKETS} />}>
            {days ? (
              <TrendChart
                dates={dates}
                bucket={bucket}
                note={affiliateAllZero ? "Affiliate profit · RM 0.00 throughout" : undefined}
                panels={[
                  {
                    title: "Profit (RM)",
                    kind: "bar",
                    height: 240,
                    series: [
                      { label: "Owner profit", values: buckets.map((b) => toRm(b.platform_profit)), color: "chart-2" },
                      ...(affiliateAllZero ? [] : [{ label: "Affiliate profit", values: buckets.map((b) => toRm(b.affiliate_profit)), color: "chart-3" as const }]),
                    ],
                    formatValue: formatRmValue,
                    formatTick: formatRmTick,
                  },
                ]}
              />
            ) : (
              <Pending error={trend.error} />
            )}
          </ReportCard>

          <ReportCard title="Margin trend">
            {days ? (
              <TrendChart
                dates={dates}
                bucket={bucket}
                emptyText="No margin yet: no paid sales in this range."
                panels={[
                  {
                    title: `Margin (% of that ${bucket === "day" ? "day" : bucket}'s paid sales)`,
                    kind: "line",
                    height: 220,
                    // ADR-104 R3 — Σ profit ÷ Σ sales per bucket; a bucket with no sales has no margin (null), not 0%.
                    series: [{ label: "Margin", values: buckets.map((b) => marginPct(b.platform_profit, b.sales)), color: "chart-2" }],
                    formatValue: (v) => `${v.toFixed(2)}%`,
                    formatTick: (v) => `${v.toFixed(0)}%`,
                  },
                ]}
              />
            ) : (
              <Pending error={trend.error} />
            )}
            <p className="mt-4 rounded-md bg-subtle px-3 py-2 text-theme-xs text-ink-muted">
              Only periods with paid sales are plotted: a period with no sales has no margin, not 0%. Recent days can read low:
              profit is recognised once delivery completes.
            </p>
          </ReportCard>
        </div>

        <ReportCard title="Profit by game" subtitle="Owner profit, this period" className="self-start">
          {byProfit && s ? (
            <HorizontalBarList
              color="chart-2"
              items={byProfit.map((g) => ({
                label: g.game_name,
                value: toRm(g.platform_profit),
                sublabel: `${formatRm(g.sales)} sales`,
                meta: g.platform_profit <= 0 ? "No profit" : s.platform_profit > 0 ? `${Math.round((g.platform_profit / s.platform_profit) * 100)}% of profit` : undefined,
                muted: g.platform_profit <= 0,
              }))}
              formatValue={formatRmValue}
            />
          ) : (
            <Pending error={games.error ?? summary.error} />
          )}
        </ReportCard>
      </div>
    </div>
  );
}
