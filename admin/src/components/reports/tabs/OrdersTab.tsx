"use client";

/**
 * Orders tab: delivery health, Failed & compensated (ADR-104 R8–R10) and
 * Delivery by game (R5). Failure is a liability, never netted from
 * profit: a failed order's profit is already 0 (profit is credited on
 * delivery), and its cash is owed back as store credit.
 */

import Link from "next/link";
import { getDeliveryByGame, getFailedCompensated, getOrderStatusFunnel } from "@/lib/reports";
import { GameIcon, Pending, ReportCard, ReportTable, ShareBar, useReport, type ReportTabProps } from "../ReportKit";
import { OrderStatusFunnelChart } from "../OrderStatusFunnelChart";
import { formatRm } from "../format";

function Stat({ label, value, sub, strong = false }: { label: string; value: string; sub?: string; strong?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-theme-xs font-medium text-ink-muted">{label}</dt>
      <dd className={`mt-1 tabular-nums tracking-tight text-ink ${strong ? "text-[22px] font-semibold leading-8" : "text-lg font-semibold"}`}>{value}</dd>
      {sub && <dd className="mt-0.5 text-theme-xs text-ink-muted">{sub}</dd>}
    </div>
  );
}

export function OrdersTab(props: ReportTabProps) {
  const { token, filters } = props;
  const funnel = useReport(props, () => getOrderStatusFunnel(token, filters));
  const failed = useReport(props, () => getFailedCompensated(token, filters));
  const delivery = useReport(props, () => getDeliveryByGame(token, filters));

  const f = funnel.data;
  const inProgress = f ? f.total - f.by_status.delivered - f.by_status.failed : 0;
  const fc = failed.data;
  // Lowest delivery rate first: the games that need a look.
  const games = delivery.data ? [...delivery.data.games].sort((a, b) => a.success_rate_pct - b.success_rate_pct || b.total - a.total) : null;

  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <ReportCard
          className="lg:col-span-2"
          title="Delivery health"
          subtitle={f ? `Where the ${f.total.toLocaleString()} paid orders in this period ended up. A health snapshot: the worklist lives in Orders.` : undefined}
        >
          {f ? <OrderStatusFunnelChart funnel={f} /> : <Pending error={funnel.error} />}
        </ReportCard>

        <ReportCard title="Delivery performance" className="self-start">
          {f ? (
            <div>
              <p className="text-metric-lg font-semibold tabular-nums tracking-tight text-ink">{f.success_rate_pct.toFixed(1)}%</p>
              <p className="mt-1 text-theme-xs text-ink-muted">
                Successful delivery rate · {f.by_status.delivered} of {f.total} paid orders
              </p>
              <dl className="mt-4 divide-y divide-border text-theme-sm">
                {[
                  ["Paid orders", f.total],
                  ["Delivered", f.by_status.delivered],
                  ["Failed", f.by_status.failed],
                  ["In progress or review", inProgress],
                ].map(([label, n]) => (
                  <div key={label} className="flex justify-between py-2.5">
                    <dt className="text-ink-muted">{label}</dt>
                    <dd className="font-semibold tabular-nums text-ink">{Number(n).toLocaleString()}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ) : (
            <Pending error={funnel.error} />
          )}
        </ReportCard>
      </div>

      <ReportCard
        title="Failed & compensated"
        subtitle="A failed order's cash is owed back to the customer. It is a liability, not an expense, so it is never taken off profit: a failed order's profit is already RM 0.00."
      >
        {fc ? (
          <div>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-5 md:grid-cols-4">
              <Stat strong label="Failed orders" value={fc.failed_count.toLocaleString()} sub={`${formatRm(fc.failed_paid_amount)} paid`} />
              <Stat label="Store credit voucher issued" value={formatRm(fc.voucher_issued)} sub="Compensation vouchers" />
              <Stat label="Refunded to reseller wallet" value={formatRm(fc.wallet_refund)} sub="Already out of Paid sales" />
              <Stat label="Checkout voucher restored" value={formatRm(fc.voucher_restored)} sub="Given back to the customer's voucher" />
            </dl>
            <div className="mt-5 flex flex-wrap items-end justify-between gap-3 rounded-md bg-subtle px-4 py-3">
              <dl>
                <Stat
                  strong
                  label="Outstanding store credit"
                  value={formatRm(fc.outstanding_store_credit)}
                  sub={`Unused, unexpired compensation vouchers, as of ${new Date(fc.outstanding_store_credit_as_of).toLocaleString("en-MY", { day: "numeric", month: "short", hour: "numeric", minute: "2-digit", timeZone: "Asia/Kuala_Lumpur" })}. Not limited to this date range.`}
                />
              </dl>
              <Link href="/admin/vouchers" className="text-theme-xs font-medium text-cyan-ink hover:underline">
                Vouchers →
              </Link>
            </div>
            <p className="mt-3 text-theme-xs text-ink-muted">
              Counted on orders paid in this range. Accounting counts vouchers by the day they were issued, so the two can differ across a month boundary.
            </p>
          </div>
        ) : (
          <Pending error={failed.error} />
        )}
      </ReportCard>

      <ReportCard
        flush
        title="Delivery by game"
        subtitle="The same paid orders, split by game. Lowest delivery rate first."
        actions={
          <Link href="/admin/orders" className="text-theme-xs font-medium text-cyan-ink hover:underline">
            Open in Orders →
          </Link>
        }
      >
        {games && f ? (
          <ReportTable
            rows={games}
            rowKey={(r) => r.game_id ?? "unknown"}
            columns={[
              {
                key: "game",
                header: "Game",
                render: (r) => (
                  <span className="flex items-center gap-3 font-medium">
                    <GameIcon name={r.game_name} src={r.image_url} />
                    {r.game_name}
                  </span>
                ),
                total: "Total",
              },
              { key: "paid", header: "Paid orders", align: "right", render: (r) => r.total, total: f.total },
              { key: "delivered", header: "Delivered", align: "right", render: (r) => r.delivered, total: f.by_status.delivered },
              { key: "failed", header: "Failed", align: "right", render: (r) => <span className={r.failed > 0 ? "font-semibold text-danger-ink" : "text-ink-muted"}>{r.failed}</span>, total: f.by_status.failed },
              { key: "progress", header: "In progress or review", align: "right", render: (r) => <span className={r.in_progress + r.partially_delivered > 0 ? "" : "text-ink-muted"}>{r.in_progress + r.partially_delivered}</span>, total: inProgress },
              {
                key: "rate",
                header: "Delivery rate",
                align: "right",
                render: (r) => (
                  <span className={`inline-flex items-center gap-2 ${r.success_rate_pct === 0 ? "text-danger-ink" : ""}`}>
                    <ShareBar pct={r.success_rate_pct} />
                    {r.success_rate_pct.toFixed(0)}%
                  </span>
                ),
                total: `${f.success_rate_pct.toFixed(0)}%`,
              },
            ]}
          />
        ) : (
          <Pending error={delivery.error ?? funnel.error} className="p-6" />
        )}
      </ReportCard>
    </div>
  );
}
