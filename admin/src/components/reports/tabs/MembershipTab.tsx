"use client";

import { getMembershipBreakdown } from "@/lib/reports";
import { KpiCard, Pending, ReportCard, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { HorizontalBarList } from "../HorizontalBarList";
import { formatRm, formatRmValue, toRm } from "../format";

const pctOf = (part: number, whole: number) => (whole > 0 ? (part / whole) * 100 : 0);

export function MembershipTab(props: ReportTabProps) {
  const { token, filters, compare } = props;
  const membership = useReport(props, () => getMembershipBreakdown(token, filters, compare));

  const m = membership.data;
  const cmp = m?.compare ?? undefined;
  const previousLabel = previousLabelOf(cmp);
  const sales = m ? m.member_sales + m.standard_sales : 0;
  const orders = m ? m.member_orders_count + m.standard_orders_count : 0;
  const memberShare = m ? pctOf(m.member_sales, sales) : 0;

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard lead label="Member sales" value={m ? formatRm(m.member_sales) : "—"} sub={m ? `${m.member_orders_count} member ${m.member_orders_count === 1 ? "order" : "orders"}` : undefined} change={cmp?.changes.member_sales} previousLabel={previousLabel} />
        <KpiCard label="Standard sales" value={m ? formatRm(m.standard_sales) : "—"} sub={m ? `${m.standard_orders_count} standard ${m.standard_orders_count === 1 ? "order" : "orders"}` : undefined} change={cmp?.changes.standard_sales} previousLabel={previousLabel} />
        <KpiCard label="Margin forgone" value={m ? formatRm(m.margin_forgone) : "—"} sub="Discount given to members" change={cmp?.changes.margin_forgone} previousLabel={previousLabel} />
        <KpiCard label="Membership fees" value={m ? formatRm(m.membership_fee_revenue) : "—"} sub="Subscription revenue (ledger)" change={cmp?.changes.membership_fee_revenue} previousLabel={previousLabel} />
      </div>

      {m ? (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <ReportCard title="Sales by pricing basis" className="self-start">
            <HorizontalBarList
              items={[
                { label: "Standard", value: toRm(m.standard_sales), sublabel: `${m.standard_orders_count} orders`, meta: `${pctOf(m.standard_sales, sales).toFixed(1)}% of sales` },
                { label: "Member", value: toRm(m.member_sales), sublabel: `${m.member_orders_count} orders`, meta: `${memberShare.toFixed(1)}% of sales` },
              ]}
              formatValue={formatRmValue}
            />
          </ReportCard>

          <ReportCard title="Member contribution" subtitle="Among member and standard orders. Reseller wallet and affiliate wholesale orders have their own pricing and sit outside this tab." className="self-start">
            <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-subtle" role="img" aria-label={`Member ${memberShare.toFixed(1)}% of sales`}>
              {m.member_sales > 0 && <span className="bg-chart-2" style={{ width: `${memberShare}%` }} />}
            </div>
            <p className="mt-2 flex gap-4 text-theme-xs text-ink-muted">
              <span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-chart-2" />Member <b className="font-semibold text-ink">{memberShare.toFixed(1)}%</b></span>
              <span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-subtle ring-1 ring-border-strong" />Standard <b className="font-semibold text-ink">{(sales > 0 ? 100 - memberShare : 0).toFixed(1)}%</b></span>
            </p>
            <dl className="mt-4 divide-y divide-border text-theme-sm">
              {[
                ["Share of sales", `${memberShare.toFixed(1)}%`, `${formatRm(m.member_sales)} of ${formatRm(sales)} member + standard`],
                ["Share of orders", `${pctOf(m.member_orders_count, orders).toFixed(1)}%`, `${m.member_orders_count} of ${orders} member + standard orders`],
                ["Forgone per member order", m.member_orders_count > 0 ? formatRm(Math.round(m.margin_forgone / m.member_orders_count)) : "—", `${formatRm(m.margin_forgone)} ÷ ${m.member_orders_count} ${m.member_orders_count === 1 ? "order" : "orders"}`],
              ].map(([label, value, sub]) => (
                <div key={label} className="flex justify-between gap-4 py-3">
                  <dt className="text-ink-muted">{label}</dt>
                  <dd className="text-right">
                    <span className="block font-semibold tabular-nums text-ink">{value}</span>
                    <span className="block text-theme-xs text-ink-muted">{sub}</span>
                  </dd>
                </div>
              ))}
            </dl>
          </ReportCard>
        </div>
      ) : (
        <Pending error={membership.error} />
      )}
    </div>
  );
}
