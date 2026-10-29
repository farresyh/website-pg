"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getOrder, type OrderDetail } from "@/lib/portal";
import { dateAndTime, deliveryBadge, formatRm, paymentBadge } from "@/lib/format";
import { useSupportContacts, whatsAppLink } from "@/hooks/useSupportContacts";
import { Badge, CopyId, ErrorNote, GameThumb, Skeleton, buttonClass } from "@/components/composites";
import { PortalIcon, type PortalIconName } from "@/components/shell/PortalIcon";

/**
 * An order as its partner sees it (ADR-128): state, then what happened to
 * the money, then the facts. Never a supplier name, supplier message, cost
 * or platform margin.
 */
export default function OrderDetailPage() {
  const params = useParams<{ orderNumber: string }>();
  const session = useClientSession();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const support = useSupportContacts();

  useEffect(() => {
    const current = getClientSession();
    if (!current || !params.orderNumber) return;
    getOrder(current.token, current.owner_type, params.orderNumber)
      .then(setOrder)
      .catch((err: unknown) =>
        setError(err instanceof ApiError && err.status === 404 ? "Order not found." : err instanceof ApiError ? err.message : "Could not load the order."),
      );
  }, [params.orderNumber]);

  const affiliate = session?.owner_type === "affiliate";
  const askSupport = support?.phone ? `${whatsAppLink(support.phone)}?text=${encodeURIComponent(`Order ${params.orderNumber}`)}` : null;

  return (
    <div className="flex flex-col gap-5">
      <Link href="/orders" className="inline-flex w-fit items-center gap-1.5 text-[13px] font-medium text-ink-muted hover:text-ink">
        <PortalIcon name="arrowLeft" className="h-4 w-4" />
        Back to Orders
      </Link>

      {error && <ErrorNote message={error} />}

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="min-w-0">
            <CopyId value={params.orderNumber} label="Copy order number" large />
          </h1>
          {order && (
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <span className="text-[13px] text-ink-subtle">Payment</span>
              <Badge tone={paymentBadge(order.payment_status).tone} large>
                {paymentBadge(order.payment_status).label}
              </Badge>
              <span className="ml-1.5 text-[13px] text-ink-subtle">Delivery</span>
              <Badge tone={deliveryBadge(order.delivery_status).tone} large>
                {deliveryBadge(order.delivery_status).label}
              </Badge>
            </div>
          )}
        </div>
        {askSupport && (
          <a href={askSupport} target="_blank" rel="noreferrer" className={buttonClass("secondary")}>
            <PortalIcon name="chat" className="h-4 w-4" />
            Ask support about this order
          </a>
        )}
      </div>

      {!order && !error && <Skeleton className="h-[320px]" />}

      {order && (
        <>
          <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border shadow-xs md:grid-cols-4">
            <Summary label="Game · package" value={order.game?.name ?? "—"} sub={order.package_name ?? "—"} />
            <Summary label={affiliate ? "Customer paid" : "Amount"} value={formatRm(order.final_amount)} sub={affiliate ? order.payment_method ?? "—" : "From your Purchase Balance"} money />
            <Summary label="Player" value={order.player_id ?? "—"} sub={order.server_id ? `Server ${order.server_id}` : undefined} mono />
            <Summary label="Placed" value={dateAndTime(order.created_at).date} sub={`${dateAndTime(order.created_at).time} MYT`} />
          </dl>

          <Resolution order={order} affiliate={affiliate} />

          <div className="grid gap-4 lg:grid-cols-2">
            <DetailCard icon="game" title="Game & player">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <GameThumb name={order.game?.name ?? ""} />
                <div className="min-w-0">
                  <p className="truncate text-base font-semibold text-ink">{order.game?.name ?? "—"}</p>
                  <p className="truncate text-[13px] text-ink-muted">{order.package_name ?? "—"}</p>
                </div>
              </div>
              <KeyValues
                rows={[
                  ["Player ID", order.player_id ? <CopyId value={order.player_id} label="Copy player ID" /> : "—"],
                  ...(order.server_id ? [["Server", order.server_id] as [string, React.ReactNode]] : []),
                  ["Delivered", order.delivered_at ? `${dateAndTime(order.delivered_at).date} · ${dateAndTime(order.delivered_at).time}` : "Not yet"],
                ]}
              />
            </DetailCard>

            {affiliate ? (
              <DetailCard icon="wallet" title="Money">
                <KeyValues
                  rows={[
                    ["Customer paid", formatRm(order.final_amount)],
                    ["Voucher discount", order.voucher_discount ? `− ${formatRm(order.voucher_discount)}` : "None"],
                    ["Payment fee", order.transaction_fee ? formatRm(order.transaction_fee) : "None"],
                    ["Your markup", `${order.affiliate_markup_pct ?? 0}%`],
                    ["Paid", order.paid_at ? `${dateAndTime(order.paid_at).date} · ${dateAndTime(order.paid_at).time}` : "Not paid"],
                  ]}
                />
                <div className="flex items-center justify-between rounded-md bg-subtle px-3.5 py-3">
                  <span className="text-[13px] font-medium text-ink-muted">Your earning</span>
                  <span className="text-base font-semibold text-ink">{order.affiliate_profit == null ? "—" : `+ ${formatRm(order.affiliate_profit)}`}</span>
                </div>
              </DetailCard>
            ) : (
              <DetailCard icon="wallet" title="Payment">
                <KeyValues
                  rows={[
                    ["Charged", formatRm(order.final_amount)],
                    ["Paid from", "Purchase Balance"],
                    ["Refunded", order.wallet_refund ? `${formatRm(order.wallet_refund.amount_sen)} · ${dateAndTime(order.wallet_refund.refunded_at).date}` : "No"],
                  ]}
                />
              </DetailCard>
            )}

            {affiliate && (
              <DetailCard icon="user" title="Customer">
                <KeyValues
                  rows={[
                    ["Name", order.customer_name ?? "—"],
                    ["Email", order.customer_email ?? "—"],
                    ["Phone", order.customer_phone ?? "—"],
                  ]}
                />
              </DetailCard>
            )}
          </div>
        </>
      )}
    </div>
  );
}

function Summary({ label, value, sub, money = false, mono = false }: { label: string; value: string; sub?: string; money?: boolean; mono?: boolean }) {
  return (
    <div className="min-w-0 bg-surface px-5 py-3.5">
      <dt className="text-xs text-ink-subtle">{label}</dt>
      <dd className={`truncate font-semibold text-ink ${money ? "text-lg leading-5 font-[650]" : "text-sm"} ${mono ? "font-mono font-medium" : ""}`}>{value}</dd>
      {sub && <dd className={`truncate text-xs text-ink-subtle ${mono ? "font-mono" : ""}`}>{sub}</dd>}
    </div>
  );
}

/** What went wrong and what happened to the money; nothing for a healthy order. */
function Resolution({ order, affiliate }: { order: OrderDetail; affiliate: boolean }) {
  const failed = order.delivery_status === "failed" || order.delivery_status === "partially_delivered";
  const review = order.delivery_status === "needs_review";
  if (!failed && !review) return null;

  const resolved = order.wallet_refund
    ? { title: "Refunded to your Purchase Balance", facts: [["Refunded", formatRm(order.wallet_refund.amount_sen)], ["When", `${dateAndTime(order.wallet_refund.refunded_at).date} · ${dateAndTime(order.wallet_refund.refunded_at).time}`]], link: { href: "/wallet", label: "See it in Wallet" } }
    : order.has_voucher_restored
      ? { title: "The customer's voucher was restored", facts: [], link: null }
      : order.has_compensation_voucher
        ? { title: "The customer got a store voucher", facts: [], link: null }
        : null;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Alert
        tone={review ? "warning" : "danger"}
        icon="info"
        title={review ? "We are checking this order" : order.delivery_status === "partially_delivered" ? "Only part of this order was delivered" : "This order could not be delivered"}
        body={
          review
            ? "Nothing is needed from you. The status changes here once the check is done."
            : resolved
              ? affiliate
                ? "The top-up did not reach the player, and the customer has been compensated."
                : "The top-up did not reach the player. The amount went back to your Purchase Balance."
              : "The top-up did not reach the player. PekanGame is sorting it out; contact support if you need an update."
        }
      />
      {resolved && (
        <Alert tone="resolved" icon="check" title={resolved.title} link={resolved.link}>
          {resolved.facts.length > 0 && (
            <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1.5 border-t border-border pt-2.5">
              {resolved.facts.map(([dt, dd]) => (
                <div key={dt}>
                  <dt className="text-[11px] text-ink-subtle">{dt}</dt>
                  <dd className="text-[13px] font-semibold text-ink">{dd}</dd>
                </div>
              ))}
            </dl>
          )}
        </Alert>
      )}
    </div>
  );
}

const ALERT_TONE = {
  danger: { icon: "bg-danger-surface text-danger-ink", frame: "border-danger-surface shadow-[inset_3px_0_0_var(--color-danger-ink)]" },
  warning: { icon: "bg-warning-surface text-warning-ink", frame: "border-border" },
  resolved: { icon: "bg-success-surface text-success-ink", frame: "border-border" },
};

function Alert({
  tone,
  icon,
  title,
  body,
  link,
  children,
}: {
  tone: keyof typeof ALERT_TONE;
  icon: PortalIconName;
  title: string;
  body?: string;
  link?: { href: string; label: string } | null;
  children?: React.ReactNode;
}) {
  return (
    <section role="status" className={`flex gap-3 rounded-lg border bg-surface px-4 py-3.5 shadow-xs ${ALERT_TONE[tone].frame}`}>
      <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-md ${ALERT_TONE[tone].icon}`}>
        <PortalIcon name={icon} />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-semibold text-ink">{title}</p>
          {link && (
            <Link href={link.href} className="inline-flex items-center gap-1 text-[13px] font-semibold text-cyan-ink hover:underline">
              {link.label} <PortalIcon name="arrowRight" className="h-3.5 w-3.5" />
            </Link>
          )}
        </div>
        {body && <p className="mt-0.5 text-[13px] leading-[19px] text-ink-muted">{body}</p>}
        {children}
      </div>
    </section>
  );
}

function DetailCard({ icon, title, children }: { icon: PortalIconName; title: string; children: React.ReactNode }) {
  return (
    <section className="flex flex-col gap-3 rounded-lg border border-border bg-surface px-5 pt-4 pb-5 shadow-xs">
      <h2 className="flex items-center gap-2 text-[15px] font-semibold text-ink">
        <span className="text-ink-subtle">
          <PortalIcon name={icon} className="h-4 w-4" />
        </span>
        {title}
      </h2>
      {children}
    </section>
  );
}

function KeyValues({ rows }: { rows: [string, React.ReactNode][] }) {
  return (
    <dl className="flex flex-col">
      {rows.map(([label, value]) => (
        <div key={label} className="grid min-h-[34px] grid-cols-[132px_minmax(0,1fr)] items-center gap-3 border-t border-border py-1.5 first:border-t-0">
          <dt className="text-[13px] text-ink-subtle">{label}</dt>
          <dd className="flex min-w-0 justify-end text-right text-[13px] text-ink">{value}</dd>
        </div>
      ))}
    </dl>
  );
}
