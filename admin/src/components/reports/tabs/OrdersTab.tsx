"use client";

/**
 * Orders tab: delivery health, Failed & compensated (ADR-104 R8–R10) and
 * Delivery by game (R5). Failure is a liability, never netted from
 * profit: a failed order's profit is already 0 (profit is credited on
 * delivery), and its cash is owed back as store credit.
 */

import Link from "next/link";
import { type CompensationBlock, getDeliveryByGame, getFailedCompensated, getOrderStatusFunnel } from "@/lib/reports";
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

/**
 * One delivery status's compensation (ADR-104 R8, revised 2026-10-09).
 * "Awaiting" is Orders' Need action scope, so the link lands on the same count.
 */
function CompensationBlockView({ title, note, block, countLabel, showPaid = true }: { title: string; note: string; block: CompensationBlock; countLabel: string; showPaid?: boolean }) {
  return (
    <section className="rounded-md border border-border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-theme-sm font-semibold text-ink">{title}</h3>
        {block.awaiting_compensation > 0 ? (
          <Link href="/admin/orders?status=need_action" className="text-theme-xs font-medium text-danger-ink hover:underline">
            {block.awaiting_compensation} awaiting compensation →
          </Link>
        ) : (
          <span className="text-theme-xs text-ink-muted">None awaiting compensation</span>
        )}
      </div>
      <p className="mt-0.5 text-theme-xs text-ink-muted">{note}</p>
      <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-5 md:grid-cols-4">
        <Stat strong label={countLabel} value={block.count.toLocaleString()} sub={showPaid ? `${formatRm(block.paid_amount)} paid` : undefined} />
        <Stat label="Store credit voucher issued" value={formatRm(block.voucher_issued)} sub="Compensation vouchers" />
        <Stat label="Refunded to reseller wallet" value={formatRm(block.wallet_refund)} sub="Already out of Paid sales" />
        <Stat label="Checkout voucher restored" value={formatRm(block.voucher_restored)} sub="Given back to the customer's voucher" />
      </dl>
    </section>
  );
}

export function OrdersTab(props: ReportTabProps) {
  const { token, filters } = props;
  const funnel = useReport(props, () => getOrderStatusFunnel(token, filters));
  const failed = useReport(props, () => getFailedCompensated(token, filters));
  const delivery = useReport(props, () => getDeliveryByGame(token, filters));

  const f = funnel.data;
  // ADR-104 R8 revision — a partial delivery is its own outcome, not "in progress".
  const inProgress = f ? f.total - f.by_status.delivered - f.by_status.failed - f.by_status.partially_delivered : 0;
  const fc = failed.data;
  const partialAwaiting = fc?.partially_delivered.awaiting_compensation;
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
                  [
                    "Partially delivered",
                    f.by_status.partially_delivered,
                    // Settled = compensated for the undelivered part; awaiting = Need action.
                    partialAwaiting !== undefined && f.by_status.partially_delivered > 0
                      ? `${f.by_status.partially_delivered - partialAwaiting} settled · ${partialAwaiting} awaiting`
                      : undefined,
                  ],
                  ["In progress or review", inProgress],
                ].map(([label, n, detail]) => (
                  <div key={label} className="flex justify-between gap-3 py-2.5">
                    <dt className="text-ink-muted">
                      {label}
                      {detail && <span className="block text-theme-xs">{detail}</span>}
                    </dt>
                    <dd className="font-semibold tabular-nums text-ink">{Number(n).toLocaleString()}</dd>
                  </div>
                ))}
              </dl>
              {/* ADR-104 R6 + ADR-108 addendum — every failed order, compensated or not; not Need action. */}
              <Link
                href="/admin/orders?status=failed"
                className="mt-4 flex h-10 items-center justify-center gap-2 rounded-md bg-cyan-600 text-[13px] font-medium text-on-cyan hover:opacity-90 dark:bg-primary dark:text-primary-contrast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring"
              >
                View failed orders →
              </Link>
              <p className="mt-2 text-center text-theme-xs text-ink-muted">Opens Orders on the Failed filter, all dates</p>
            </div>
          ) : (
            <Pending error={funnel.error} />
          )}
        </ReportCard>
      </div>

      <ReportCard
        title="Failed & compensated"
        subtitle="Money owed back on orders that did not deliver in full, and how it was given back. Counted on orders paid in this range."
      >
        {fc ? (
          <div className="flex flex-col gap-4">
            <CompensationBlockView
              title="Failed"
              countLabel="Failed orders"
              note="A liability, not an expense, so it is never taken off profit: a failed order's profit is already RM 0.00."
              block={fc.failed}
            />
            <CompensationBlockView
              title="Partially delivered"
              countLabel="Partial orders"
              note="Already inside profit: on settlement, the compensation is deducted from that order's profit and only the delivered part is recognised."
              block={fc.partially_delivered}
            />
            {fc.other.count > 0 && (
              <CompensationBlockView
                title="Other (under review)"
                countLabel="Compensated orders"
                note="Compensation on an order now in another status, e.g. paid late after it was compensated. An admin resolves it in Orders."
                block={fc.other}
                showPaid={false}
              />
            )}
            <div className="flex flex-wrap items-end justify-between gap-3 rounded-md bg-subtle px-4 py-3">
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
            <p className="text-theme-xs text-ink-muted">
              Accounting counts vouchers by the day they were issued, so the two can differ across a month boundary.
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
              { key: "partial", header: "Partial", align: "right", render: (r) => <span className={r.partially_delivered > 0 ? "" : "text-ink-muted"}>{r.partially_delivered}</span>, total: f.by_status.partially_delivered },
              { key: "progress", header: "In progress or review", align: "right", render: (r) => <span className={r.in_progress > 0 ? "" : "text-ink-muted"}>{r.in_progress}</span>, total: inProgress },
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
