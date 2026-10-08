"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportTrendDay, getReportTrend } from "@/lib/reports";
import { type TrendBucket, autoBucket, bucketRows, marginPct } from "@/lib/report-buckets";
import { ReportCard, SegmentedControl } from "../ReportKit";
import { TrendChart } from "../TrendChart";
import { formatRmTick, formatRmValue, toRm } from "../format";

const BUCKETS: { value: TrendBucket; label: string }[] = [
  { value: "day", label: "Daily" },
  { value: "week", label: "Weekly" },
  { value: "month", label: "Monthly" },
];

export function ProfitAnalysisTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [trend, setTrend] = useState<ReportTrendDay[] | null>(null);
  const [bucketChoice, setBucketChoice] = useState<TrendBucket | null>(null);

  useEffect(() => {
    // ADR-086 filter-unification follow-up — both charts below share one
    // fetch and follow the page's own filter.
    let current = true;
    getReportTrend(token, filters)
      .then((res) => current && setTrend(res.days))
      .catch(() => undefined);
    return () => {
      current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.from, filters.to, filters.affiliateId]);

  const bucket = bucketChoice ?? autoBucket(trend?.length ?? 0);
  const buckets = trend ? bucketRows(trend, bucket, ["sales", "platform_profit", "affiliate_profit"]) : [];
  const dates = buckets.map((b) => b.date);
  const affiliateAllZero = buckets.every((b) => b.affiliate_profit === 0);

  return (
    <div className="grid grid-cols-1 gap-6">
      <ReportCard
        title="Profit trend"
        actions={<SegmentedControl label="Bucket" value={bucket} onChange={setBucketChoice} options={BUCKETS} />}
      >
        {trend ? (
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
                  ...(affiliateAllZero
                    ? []
                    : [{ label: "Affiliate profit", values: buckets.map((b) => toRm(b.affiliate_profit)), color: "chart-3" as const }]),
                ],
                formatValue: formatRmValue,
                formatTick: formatRmTick,
              },
            ]}
          />
        ) : (
          <p className="text-sm text-ink-muted">Loading…</p>
        )}
      </ReportCard>

      <ReportCard title="Margin trend">
        {trend ? (
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
          <p className="text-sm text-ink-muted">Loading…</p>
        )}
        <p className="mt-4 rounded-md bg-subtle px-3 py-2 text-theme-xs text-ink-muted">
          Only periods with paid sales are plotted: a period with no sales has no margin, not 0%. Recent days can read low:
          profit is recognised once delivery completes.
        </p>
      </ReportCard>
    </div>
  );
}
