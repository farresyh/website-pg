"use client";

import { useState } from "react";
import Link from "next/link";
import {
  getAccountingBridge,
  getGameBreakdown,
  getOrderStatusFunnel,
  getReportDailyBreakdown,
  getReportSummary,
  getReportTrend,
} from "@/lib/reports";
import { type TrendBucket, autoBucket, bucketRows } from "@/lib/report-buckets";
import { KpiCard, Pending, ReportCard, ReportTable, SegmentedControl, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { TrendChart, formatBucketLabel } from "../TrendChart";
import { HorizontalBarList, TabLink } from "../HorizontalBarList";
import { AccountingBridge } from "../AccountingBridge";
import { formatRm, formatRmTick, formatRmValue, toRm } from "../format";

const BUCKETS: { value: TrendBucket; label: string }[] = [
  { value: "day", label: "Daily" },
  { value: "week", label: "Weekly" },
  { value: "month", label: "Monthly" },
];

const BREAKDOWN_KEYS = ["orders_count", "sales", "platform_profit", "affiliate_profit", "transaction_fees"] as const;

function timeAgo(iso: string): string {
  const minutes = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

/** Zero values are muted, as in the mockup; only a loss would stand out. */
function Money({ sen }: { sen: number }) {
  return <span className={sen === 0 ? "text-ink-muted" : sen < 0 ? "text-danger-ink" : ""}>{formatRm(sen)}</span>;
}

export function OverviewTab(props: ReportTabProps) {
  const { token, filters, compare, rangeIncludesToday, onOpenTab } = props;
  const summary = useReport(props, () => getReportSummary(token, filters, compare));
  const funnel = useReport(props, () => getOrderStatusFunnel(token, filters));
  const games = useReport(props, () => getGameBreakdown(token, filters));
  const daily = useReport(props, () => getReportDailyBreakdown(token, filters));
  const trend = useReport(props, () => getReportTrend(token, filters));
  const bridge = useReport(props, () => getAccountingBridge(token, filters));

  const [measure, setMeasure] = useState<"money" | "orders">("money");
  // ADR-104 R17 — the bucket opens on the range's own size; the toggle overrides it.
  const [bucketChoice, setBucketChoice] = useState<TrendBucket | null>(null);

  const s = summary.data;
  const cmp = s?.compare ?? undefined;
  const previousLabel = previousLabelOf(cmp);
  // ADR-104 R13 — profit is credited on delivery, so a range ending today still moves.
  const profitNote = rangeIncludesToday ? "Profit is recognised on delivery" : undefined;

  const trendDays = trend.data?.days;
  const bucket = bucketChoice ?? autoBucket(trendDays?.length ?? 0);
  const buckets = trendDays ? bucketRows(trendDays, bucket, ["sales", "platform_profit", "orders_count"]) : [];
  const dates = buckets.map((b) => b.date);

  // R18 — the breakdown table follows the chart's bucket, newest first.
  const breakdown = daily.data ? bucketRows(daily.data.days, bucket, [...BREAKDOWN_KEYS]).sort((a, b) => b.date.localeCompare(a.date)) : null;
  const gameCount = games.data?.games.length;

  return (
    <div>
      <div className="mb-3 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
        <KpiCard lead label="Paid sales" value={s ? formatRm(s.total_sales) : "—"} sub={s ? `${s.orders_count.toLocaleString()} paid orders` : undefined} change={cmp?.changes.total_sales} previousLabel={previousLabel} onClick={() => onOpenTab("sales")} />
        <KpiCard lead label="Owner profit" value={s ? formatRm(s.platform_profit) : "—"} sub={s ? `${s.margin_pct.toFixed(2)}% margin` : undefined} change={cmp?.changes.platform_profit} previousLabel={previousLabel} note={profitNote} onClick={() => onOpenTab("profit")} />
        <KpiCard label="Paid orders" value={s ? s.orders_count.toLocaleString() : "—"} sub={funnel.data ? `${funnel.data.by_status.delivered} delivered · ${funnel.data.by_status.failed} failed` : undefined} change={cmp?.changes.orders_count} previousLabel={previousLabel} onClick={() => onOpenTab("orders")} />
        <KpiCard label="Avg order value" value={s ? formatRm(s.avg_order_value) : "—"} sub="Per paid order" change={cmp?.changes.avg_order_value} previousLabel={previousLabel} />
        <KpiCard label="Affiliate profit" value={s ? formatRm(s.affiliate_profit) : "—"} sub="Earned by affiliates" change={cmp?.changes.affiliate_profit} previousLabel={previousLabel} onClick={() => onOpenTab("partners")} />
      </div>
      {summary.error && <Pending error={summary.error} className="mb-3" />}

      {s?.latest_order && (
        <p className="mb-4 flex flex-wrap items-center gap-x-1.5 text-theme-xs text-ink-muted">
          Latest paid order
          <span className="font-mono text-code-id text-ink">{s.latest_order.order_number}</span>·{" "}
          {formatRm(s.latest_order.final_amount)} · {timeAgo(s.latest_order.paid_at)}
          <Link href={`/admin/orders?order=${s.latest_order.id}`} className="ml-1 font-medium text-cyan-ink hover:underline">
            Open →
          </Link>
        </p>
      )}

      {bridge.data?.bridge && <AccountingBridge bridge={bridge.data.bridge} />}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <ReportCard
          className="lg:col-span-2"
          title={measure === "money" ? "Revenue & profit" : "Paid orders"}
          subtitle={measure === "money" && s && s.total_sales > 0 ? `Profit has its own scale — it is about ${Math.round(s.margin_pct)}% of revenue.` : undefined}
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
          {trendDays ? (
            <TrendChart
              dates={dates}
              bucket={bucket}
              panels={
                measure === "money"
                  ? [
                      { title: "Revenue (RM)", kind: "bar", series: [{ label: "Revenue", values: buckets.map((b) => toRm(b.sales)), color: "chart-1" }], formatValue: formatRmValue, formatTick: formatRmTick },
                      { title: "Owner profit (RM)", kind: "bar", series: [{ label: "Owner profit", values: buckets.map((b) => toRm(b.platform_profit)), color: "chart-2" }], formatValue: formatRmValue, formatTick: formatRmTick },
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
            <Pending error={trend.error} />
          )}
        </ReportCard>

        <ReportCard title="Top games" subtitle="By paid sales" className="self-start">
          {games.data ? (
            <HorizontalBarList
              ranked
              items={games.data.games.slice(0, 3).map((g) => ({
                label: g.game_name,
                value: toRm(g.sales),
                sublabel: `${g.orders_count} orders · ${g.pct_of_sales.toFixed(1)}%`,
              }))}
              formatValue={formatRmValue}
              footer={
                <>
                  <span>{gameCount !== undefined ? `${gameCount} ${gameCount === 1 ? "game" : "games"} with paid orders` : ""}</span>
                  <TabLink onClick={() => onOpenTab("games")}>All games</TabLink>
                </>
              }
            />
          ) : (
            <Pending error={games.error} />
          )}
        </ReportCard>
      </div>

      <ReportCard
        flush
        className="mt-6"
        title={bucket === "day" ? "Daily breakdown" : bucket === "week" ? "Weekly breakdown" : "Monthly breakdown"}
        subtitle="Periods with at least one paid order, following the chart's Daily / Weekly / Monthly toggle."
      >
        {breakdown && s ? (
          <ReportTable
            rows={breakdown}
            rowKey={(r) => r.date}
            columns={[
              { key: "date", header: bucket === "month" ? "Month" : bucket === "week" ? "Week" : "Date", render: (r) => <span className="font-medium">{formatBucketLabel(r.date, bucket)}</span>, total: "Total" },
              { key: "orders", header: "Orders", align: "right", render: (r) => r.orders_count, total: s.orders_count },
              { key: "sales", header: "Sales", align: "right", render: (r) => <span className="font-semibold">{formatRm(r.sales)}</span>, total: formatRm(s.total_sales) },
              { key: "owner", header: "Owner profit", align: "right", render: (r) => <Money sen={r.platform_profit} />, total: formatRm(s.platform_profit) },
              { key: "affiliate", header: "Affiliate profit", align: "right", render: (r) => <Money sen={r.affiliate_profit} />, total: formatRm(s.affiliate_profit) },
              { key: "fees", header: "Fees", align: "right", render: (r) => <Money sen={r.transaction_fees} />, total: formatRm(breakdown.reduce((sum, r) => sum + r.transaction_fees, 0)) },
              { key: "avg", header: "Avg order", align: "right", render: (r) => formatRm(Math.round(r.sales / r.orders_count)), total: formatRm(s.avg_order_value) },
            ]}
          />
        ) : (
          <Pending error={daily.error ?? summary.error} className="p-6" />
        )}
      </ReportCard>
    </div>
  );
}
