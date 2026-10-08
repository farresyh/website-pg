"use client";

/**
 * ADR-104 R2 — Sales absorbs the old Payment Methods tab (both rendered
 * the same `paymentMethodBreakdown()`), with its "avg per order" column.
 * R6 — the mockup's "Paid orders per day" chart is dropped (Overview's
 * Orders toggle shows it); R15 puts Sales by channel in its place.
 */

import {
  CHANNEL_LABELS,
  getChannelBreakdown,
  getGameBreakdown,
  getPaymentMethodBreakdown,
  getReportDailyBreakdown,
  getReportSummary,
} from "@/lib/reports";
import { paymentMethodLabel } from "@/lib/payment-methods";
import { KpiCard, Pending, ReportCard, ReportTable, ShareBar, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { HorizontalBarList, TabLink } from "../HorizontalBarList";
import { formatRm, formatRmValue, toRm } from "../format";

const pctOf = (part: number, whole: number) => (whole > 0 ? (part / whole) * 100 : 0);

export function SalesTab(props: ReportTabProps) {
  const { token, filters, compare, onOpenTab } = props;
  const summary = useReport(props, () => getReportSummary(token, filters, compare));
  const methods = useReport(props, () => getPaymentMethodBreakdown(token, filters));
  const games = useReport(props, () => getGameBreakdown(token, filters));
  const channels = useReport(props, () => getChannelBreakdown(token, filters));
  const daily = useReport(props, () => getReportDailyBreakdown(token, filters));

  const s = summary.data;
  const cmp = s?.compare ?? undefined;
  const previousLabel = previousLabelOf(cmp);
  const top = games.data?.games[0];
  const dayCount = daily.data?.days.length;
  const methodRows = methods.data?.payment_methods;
  const channelRows = channels.data?.channels;
  const channelTotal = channelRows?.reduce((sum, c) => sum + c.sales, 0) ?? 0;

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard lead label="Paid sales" value={s ? formatRm(s.total_sales) : "—"} sub={s ? `${s.orders_count.toLocaleString()} paid orders` : undefined} change={cmp?.changes.total_sales} previousLabel={previousLabel} />
        <KpiCard label="Paid orders" value={s ? s.orders_count.toLocaleString() : "—"} sub={dayCount !== undefined ? `Across ${dayCount} ${dayCount === 1 ? "day" : "days"}` : undefined} change={cmp?.changes.orders_count} previousLabel={previousLabel} onClick={() => onOpenTab("orders")} />
        <KpiCard label="Avg order" value={s ? formatRm(s.avg_order_value) : "—"} sub="Per paid order" change={cmp?.changes.avg_order_value} previousLabel={previousLabel} />
        <KpiCard label="Top game" value={top?.game_name ?? "—"} sub={top ? `${formatRm(top.sales)} · ${top.pct_of_sales.toFixed(1)}% of sales` : undefined} onClick={() => onOpenTab("games")} />
      </div>
      {summary.error && <Pending error={summary.error} className="-mt-3 mb-6" />}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <ReportCard title="Sales by payment method" className="self-start">
          {methodRows ? (
            <HorizontalBarList
              items={methodRows.map((m) => ({ label: paymentMethodLabel(m.payment_method), value: toRm(m.sales), sublabel: `${m.pct_of_sales.toFixed(1)}% of sales`, meta: `${m.orders_count} orders` }))}
              formatValue={formatRmValue}
              footer={<span>{methodRows.length} {methodRows.length === 1 ? "method" : "methods"} used</span>}
            />
          ) : (
            <Pending error={methods.error} />
          )}
        </ReportCard>

        <ReportCard title="Sales by game" className="self-start">
          {games.data ? (
            <HorizontalBarList
              ranked
              items={games.data.games.slice(0, 5).map((g) => ({ label: g.game_name, value: toRm(g.sales), sublabel: `${g.pct_of_sales.toFixed(1)}% of sales`, meta: `${g.orders_count} orders` }))}
              formatValue={formatRmValue}
              footer={
                <>
                  <span>
                    {games.data.games.length} {games.data.games.length === 1 ? "game" : "games"} with paid orders
                  </span>
                  <TabLink onClick={() => onOpenTab("games")}>Games tab</TabLink>
                </>
              }
            />
          ) : (
            <Pending error={games.error} />
          )}
        </ReportCard>

        <ReportCard flush title="Payment method detail" subtitle="Avg order is sales ÷ orders for each method." className="self-start">
          {methodRows && s ? (
            <ReportTable
              rows={methodRows}
              rowKey={(r) => r.payment_method}
              columns={[
                { key: "method", header: "Payment method", render: (r) => <span className="font-medium">{paymentMethodLabel(r.payment_method)}</span>, total: "Total" },
                { key: "sales", header: "Sales", align: "right", render: (r) => <span className="font-semibold">{formatRm(r.sales)}</span>, total: formatRm(s.total_sales) },
                { key: "share", header: "% of sales", align: "right", render: (r) => <span className="inline-flex items-center gap-2"><ShareBar pct={r.pct_of_sales} />{r.pct_of_sales.toFixed(1)}%</span>, total: methodRows.length > 0 ? "100%" : "" },
                { key: "orders", header: "Orders", align: "right", render: (r) => r.orders_count, total: s.orders_count },
                { key: "avg", header: "Avg order", align: "right", render: (r) => formatRm(Math.round(r.sales / r.orders_count)), total: formatRm(s.avg_order_value) },
              ]}
            />
          ) : (
            <Pending error={methods.error ?? summary.error} className="p-6" />
          )}
        </ReportCard>

        <ReportCard title="Sales by channel" subtitle="Who the order came through. Reseller wallet is checked first." className="self-start">
          {channelRows ? (
            <HorizontalBarList
              items={channelRows.map((c) => ({
                label: CHANNEL_LABELS[c.channel],
                value: toRm(c.sales),
                sublabel: `${pctOf(c.sales, channelTotal).toFixed(1)}% of sales`,
                meta: `${c.orders_count} orders`,
                muted: c.orders_count === 0,
              }))}
              formatValue={formatRmValue}
              footer={
                <>
                  <span>Own brand vs external follows each affiliate&apos;s current setting.</span>
                  <TabLink onClick={() => onOpenTab("partners")}>Partners tab</TabLink>
                </>
              }
            />
          ) : (
            <Pending error={channels.error} />
          )}
        </ReportCard>
      </div>
    </div>
  );
}
