"use client";

/**
 * The Dashboard (ADR-104 2026-10-15): a daily briefing. One summary
 * sentence, what needs action now (`/dashboard/health`, polled every 60s),
 * then how the business did in the chosen period. Every business figure
 * comes from `/reports/*`, so it always equals Reports; this page only
 * phrases and lays them out. It always opens on Today.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Refresh } from "@primeicons/react/refresh";
import { Input } from "@/components/ui/input";
import { useClientSession } from "@/hooks/useClientSession";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { getClientSession } from "@/lib/session";
import { resolveDateRange, todayInKL, type DateRangePreset } from "@/lib/date-range";
import { getDashboardHealth, type DashboardHealth } from "@/lib/dashboard";
import { summarySentence } from "@/lib/dashboard-briefing";
import { autoBucket } from "@/lib/report-buckets";
import {
  CHANNEL_LABELS,
  getBusyHours,
  getChannelBreakdown,
  getHighlights,
  getReportSummary,
  getReportTrend,
  getTopGames,
  type PercentChange,
  type ReportFilters,
} from "@/lib/reports";
import { KpiCard, Pending, ReportCard, SegmentedControl, ToolbarButton, previousLabelOf, useReport } from "@/components/reports/ReportKit";
import { HorizontalBarList } from "@/components/reports/HorizontalBarList";
import { TrendChart } from "@/components/reports/TrendChart";
import { formatRm, formatRmTick, formatRmValue, formatShortRange, toRm } from "@/components/reports/format";
import { StatusStrip } from "@/components/dashboard/StatusStrip";
import { JokiCard } from "@/components/dashboard/JokiCard";
import { BusyHours } from "@/components/dashboard/BusyHours";
import { Highlights } from "@/components/dashboard/Highlights";

type Period = Extract<DateRangePreset, "today" | "7d" | "30d" | "custom">;

const PERIODS: { value: Period; label: string }[] = [
  { value: "today", label: "Today" },
  { value: "7d", label: "Last 7 days" },
  { value: "30d", label: "Last 30 days" },
  { value: "custom", label: "Custom" },
];

const HEALTH_POLL_MS = 60_000;
const PLACED_VIA: Record<string, string> = { reseller_bot: "WhatsApp bot", reseller_api: "API", storefront: "Portal" };

function addDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function dayCount(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1;
}

function changeText(change: PercentChange | undefined): string {
  if (!change || change.direction === "flat") return "No change";
  if (change.direction === "new") return "New";
  return `${change.direction === "up" ? "▲" : "▼"} ${change.pct?.toFixed(1)}%`;
}

export default function AdminDashboardPage() {
  const router = useRouter();
  const session = useClientSession();
  const [period, setPeriod] = useState<Period>("today");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const [health, setHealth] = useState<DashboardHealth | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [updatedAt, setUpdatedAt] = useState(() => Date.now());
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!getClientSession()) router.replace("/login");
  }, [router]);

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(id);
  }, []);

  // ADR-045 decision 22 — the only figures polled on a timer.
  useEffect(() => {
    if (!session) return;
    let current = true;
    const load = () =>
      getDashboardHealth()
        .then((data) => current && setHealth(data))
        .catch(() => undefined);
    load();
    const id = setInterval(load, HEALTH_POLL_MS);
    return () => {
      current = false;
      clearInterval(id);
    };
  }, [session, refreshKey]);

  const settledFrom = useDebouncedValue(customFrom);
  const settledTo = useDebouncedValue(customTo);
  const { from, to } = resolveDateRange(period, settledFrom, settledTo);

  function refetch(fn: () => void) {
    const t = Date.now();
    setUpdatedAt(t);
    setNow(t);
    fn();
  }

  const minutesAgo = Math.floor((now - updatedAt) / 60_000);

  return (
    <div className="flex flex-col gap-6">
      {session && (
        <Briefing
          key={`${from}|${to}|${refreshKey}`}
          period={period}
          filters={{ from, to }}
          health={health}
          actions={
            <div className="flex items-center gap-2">
              <span className="mr-2 flex items-center gap-1.5 whitespace-nowrap text-theme-xs text-ink-muted" aria-live="polite">
                <span className="size-1.5 rounded-full bg-success-ink" />
                {minutesAgo < 1 ? "Updated just now" : `Updated ${minutesAgo} min ago`}
              </span>
              <ToolbarButton aria-label="Refresh" title="Refresh" className="w-9 justify-center px-0" onClick={() => refetch(() => setRefreshKey((k) => k + 1))}>
                <Refresh width={14} height={14} />
              </ToolbarButton>
            </div>
          }
          periodControl={
            <div className="flex flex-wrap items-center gap-3">
              <div className="max-sm:w-full max-sm:[&>div]:flex max-sm:[&>div]:w-full max-sm:[&_button]:flex-1">
                <SegmentedControl label="Period" options={PERIODS} value={period} onChange={(v) => refetch(() => setPeriod(v))} />
              </div>
              {period === "custom" && (
                <span className="flex items-center gap-2">
                  <Input type="date" aria-label="From" value={customFrom} max={todayInKL()} onChange={(e) => refetch(() => setCustomFrom(e.target.value))} className="w-[9.5rem]" />
                  <span className="text-theme-xs text-ink-muted">to</span>
                  <Input type="date" aria-label="To" value={customTo} max={todayInKL()} onChange={(e) => refetch(() => setCustomTo(e.target.value))} className="w-[9.5rem]" />
                </span>
              )}
              {from && to && (
                <span className="text-theme-xs text-ink-subtle">
                  {formatShortRange(from, to)}
                  {to === todayInKL() && `, to ${new Date(now).toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", timeZone: "Asia/Kuala_Lumpur" })}`}
                </span>
              )}
              <span className="ml-auto text-theme-xs text-ink-muted max-md:ml-0 max-md:w-full">Paid orders only · Malaysia time (GMT+8)</span>
            </div>
          }
        />
      )}
    </div>
  );
}

/** Everything the period drives. Remounted (by key) when the period or Refresh changes. */
function Briefing({
  period,
  filters,
  health,
  actions,
  periodControl,
}: {
  period: Period;
  filters: ReportFilters;
  health: DashboardHealth | null;
  actions: React.ReactNode;
  periodControl: React.ReactNode;
}) {
  const bounded = Boolean(filters.from && filters.to);
  const compare = bounded ? "previous" : undefined;
  const days = bounded ? dayCount(filters.from!, filters.to!) : 0;
  // A one-day trend is one bar, so short periods show the last 14 days (grill S3).
  const trendFilters: ReportFilters = bounded && days < 7 ? { from: addDays(filters.to!, -13), to: filters.to } : filters;
  const ctx = { filters, compare } as const;

  const summary = useReport(ctx, () => getReportSummary(filters, compare));
  const trend = useReport(ctx, () => getReportTrend(trendFilters));
  const channels = useReport(ctx, () => getChannelBreakdown(filters));
  const games = useReport(ctx, () => getTopGames(filters, 5, compare));
  const hours = useReport(ctx, () => getBusyHours(filters));
  const facts = useReport(ctx, () => getHighlights(filters));

  const periodLabel = period === "today" ? "Today" : period === "7d" ? "Last 7 days" : period === "30d" ? "Last 30 days" : bounded ? formatShortRange(filters.from!, filters.to!) : "All time";
  const previousPhrase = period === "today" ? "yesterday so far" : period === "custom" ? "the previous period" : `the ${days} days before`;
  const emptyText = period === "today" ? "No paid orders today" : "No paid orders in this period";
  const paymentsOff = health?.attention.items.some((i) => i.key === "payments_off") ?? false;
  const s = summary.data;
  const prev = previousLabelOf(s?.compare);
  const topGame = games.data?.games[0];

  const sentence =
    s && health
      ? summarySentence({
          periodLabel,
          previousLabel: previousPhrase,
          sales: s.total_sales,
          orders: s.orders_count,
          change: s.compare?.changes.total_sales ?? null,
          topGame: topGame && s.orders_count > 0 ? { name: topGame.game_name, sales: topGame.sales } : null,
          lastPaidAt: health.attention.last_paid_at,
          paymentsOff,
        })
      : "Loading today's briefing…";

  const channelTotal = channels.data?.channels.reduce((sum, c) => sum + c.sales, 0) ?? 0;
  const walletSplit = (channels.data?.reseller_wallet_by_placed_via ?? []).filter((v) => v.sales > 0);

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-page-title font-semibold tracking-tight text-ink">Dashboard</h1>
          <p className="mt-1 max-w-3xl text-theme-sm text-ink-muted">{sentence}</p>
        </div>
        {actions}
      </div>

      {periodControl}

      {health ? <StatusStrip health={health} /> : <Pending error={null} />}

      {health && <JokiCard counts={health.joki} />}

      <section aria-label="Key figures" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {s ? (
          <>
            <div className="max-sm:col-span-2 [&>*]:h-full">
              <KpiCard lead label="Sales" value={formatRm(s.total_sales)} change={s.compare?.changes.total_sales} previousLabel={prev} sub={s.orders_count === 0 ? emptyText : undefined} />
            </div>
            <div className="max-sm:col-span-2 [&>*]:h-full">
              <KpiCard lead label="Owner profit (market rate)" value={formatRm(s.platform_profit)} change={s.compare?.changes.platform_profit} previousLabel={prev} />
            </div>
            <KpiCard label="Orders" value={s.orders_count.toLocaleString()} change={s.compare?.changes.orders_count} previousLabel={prev} />
            <KpiCard
              label="Margin"
              value={s.orders_count > 0 ? `${s.margin_pct.toFixed(2)}%` : "—"}
              change={s.orders_count > 0 ? s.compare?.changes.margin_pct : undefined}
              previousLabel={prev}
              sub={s.orders_count > 0 ? undefined : "Needs at least one paid order"}
            />
          </>
        ) : (
          <Pending error={summary.error} className="col-span-full" />
        )}
      </section>

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <ReportCard title="Sales trend" subtitle={days > 0 && days < 7 ? "Short periods show the last 14 days; today is the last bar. Profit has its own scale." : "Daily paid sales and owner profit. Profit has its own scale."}>
          {trend.data ? (
            <TrendChart
              dates={trend.data.days.map((d) => d.date)}
              bucket={autoBucket(trend.data.days.length)}
              panels={[
                { title: "Sales (RM)", kind: "bar", series: [{ label: "Sales", values: trend.data.days.map((d) => toRm(d.sales)), color: "chart-1" }], formatValue: formatRmValue, formatTick: formatRmTick },
                { title: "Owner profit (RM)", kind: "bar", height: 110, series: [{ label: "Owner profit", values: trend.data.days.map((d) => toRm(d.platform_profit)), color: "chart-2" }], formatValue: formatRmValue, formatTick: formatRmTick },
              ]}
              emptyText="No paid orders in the last 14 days."
            />
          ) : (
            <Pending error={trend.error} />
          )}
        </ReportCard>

        <ReportCard title="Channels" subtitle="Who the orders came through">
          {channels.data ? (
            channelTotal === 0 ? (
              <p className="py-10 text-center text-sm text-ink-muted">{emptyText}</p>
            ) : (
              <HorizontalBarList
                items={channels.data.channels.map((c) => ({
                  label: CHANNEL_LABELS[c.channel],
                  value: c.sales,
                  sublabel: `${c.orders_count} ${c.orders_count === 1 ? "order" : "orders"}`,
                  meta: `${((c.sales / channelTotal) * 100).toFixed(1)}% of sales`,
                  muted: c.sales === 0,
                }))}
                formatValue={formatRm}
                footer={
                  <>
                    <span>{walletSplit.length > 0 ? `Reseller wallet: ${walletSplit.map((v) => `${PLACED_VIA[v.placed_via] ?? v.placed_via} ${formatRm(v.sales)}`).join(" · ")}` : "Reseller wallet is checked first"}</span>
                    <Link href="/admin/reports" className="shrink-0 font-medium text-cyan-ink hover:underline">
                      Reports →
                    </Link>
                  </>
                }
              />
            )
          ) : (
            <Pending error={channels.error} />
          )}
        </ReportCard>
      </div>

      <div className="grid items-start gap-4 xl:grid-cols-2">
        <ReportCard title="Top games" subtitle="By paid sales">
          {games.data ? (
            games.data.games.length === 0 ? (
              <p className="py-10 text-center text-sm text-ink-muted">{emptyText}</p>
            ) : (
              <HorizontalBarList
                ranked
                items={games.data.games.map((g) => ({
                  label: g.game_name,
                  value: g.sales,
                  sublabel: `${g.orders_count} ${g.orders_count === 1 ? "order" : "orders"}`,
                  meta: games.data?.compare ? `${changeText(games.data.compare.changes[String(g.game_id)])} vs ${previousLabelOf(games.data.compare)}` : `${g.pct_of_sales.toFixed(1)}% of sales`,
                }))}
                formatValue={formatRm}
                footer={
                  <>
                    <span>Top {games.data.games.length} by paid sales</span>
                    <Link href="/admin/reports" className="font-medium text-cyan-ink hover:underline">
                      All games →
                    </Link>
                  </>
                }
              />
            )
          ) : (
            <Pending error={games.error} />
          )}
        </ReportCard>

        <ReportCard title="Busy hours" subtitle="Paid orders by hour, summed over this period">
          {hours.data ? <BusyHours hours={hours.data.hours} emptyText={period === "today" ? "No paid orders today. Choose Last 30 days to see when orders usually come in." : "No paid orders in this period."} /> : <Pending error={hours.error} />}
        </ReportCard>
      </div>

      {facts.data && <Highlights facts={facts.data} />}
    </>
  );
}
