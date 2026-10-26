"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import type { OrderListItem } from "@/lib/portal";
import { compensationNote, dateAndTime, deliveryBadge, formatRm, paymentBadge } from "@/lib/format";
import { Badge, CopyId, GameCell, buttonClass } from "@/components/composites";

/**
 * Orders for either role: a table from `md`, tappable cards below it, with
 * Payment and Delivery always two labelled facts (ADR-112 decision 4). An
 * Affiliate also sees its own earning per order.
 */
export default function OrderTable({ orders, ownerType }: { orders: OrderListItem[]; ownerType: "affiliate" | "reseller" }) {
  const router = useRouter();
  const affiliate = ownerType === "affiliate";

  return (
    <>
      <ul className="flex flex-col gap-2 p-3 md:hidden">
        {orders.map((order) => (
          <li key={order.order_number}>
            <OrderCard order={order} affiliate={affiliate} />
          </li>
        ))}
      </ul>
      <div className="relative hidden overflow-x-auto md:block">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="bg-subtle text-xs font-semibold text-ink-subtle [&>th]:h-10 [&>th]:whitespace-nowrap [&>th]:border-b [&>th]:border-border [&>th]:px-3 [&>th:first-child]:pl-5 [&>th:last-child]:pr-5">
              <th>Order #</th>
              <th>Game / package</th>
              <th className="text-right">{affiliate ? "Sale" : "Amount"}</th>
              {affiliate && <th className="text-right">Your earning</th>}
              <th>Payment</th>
              <th>Delivery</th>
              <th>Date</th>
              <th>
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {orders.map((order) => {
              const delivery = deliveryBadge(order.delivery_status);
              const payment = paymentBadge(order.payment_status);
              const note = compensationNote(order);
              const when = dateAndTime(order.paid_at ?? order.created_at);
              const href = `/orders/${order.order_number}`;
              return (
                <tr
                  key={order.order_number}
                  onClick={() => router.push(href)}
                  className="cursor-pointer border-b border-border text-[13px] text-ink-muted last:border-b-0 hover:bg-subtle [&>td]:h-[60px] [&>td]:px-3 [&>td]:align-middle [&>td:first-child]:pl-5 [&>td:last-child]:pr-5"
                >
                  <td>
                    <CopyId value={order.order_number} label="Copy order number" />
                  </td>
                  <td className="max-w-[280px]">
                    <GameCell game={order.game?.name} pkg={order.package_name} />
                  </td>
                  <td className="whitespace-nowrap text-right text-sm font-semibold text-ink">{formatRm(order.final_amount)}</td>
                  {affiliate && (
                    <td className="whitespace-nowrap text-right text-sm font-semibold text-ink">
                      {order.affiliate_profit == null ? <span className="text-ink-subtle">—</span> : `+ ${formatRm(order.affiliate_profit)}`}
                    </td>
                  )}
                  <td>
                    <Badge tone={payment.tone}>{payment.label}</Badge>
                  </td>
                  <td>
                    <span className="flex flex-col items-start gap-0.5">
                      <Badge tone={delivery.tone}>{delivery.label}</Badge>
                      {note && <span className="whitespace-nowrap text-xs text-ink-subtle">{note}</span>}
                    </span>
                  </td>
                  <td className="whitespace-nowrap">
                    <span className="block text-[13px] font-medium text-ink-muted">{when.date}</span>
                    <span className="block text-xs text-ink-subtle">{when.time}</span>
                  </td>
                  <td className="text-right">
                    <Link href={href} onClick={(e) => e.stopPropagation()} className={buttonClass("secondary", true)}>
                      View
                    </Link>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </>
  );
}

function OrderCard({ order, affiliate }: { order: OrderListItem; affiliate: boolean }) {
  const delivery = deliveryBadge(order.delivery_status);
  const payment = paymentBadge(order.payment_status);
  const note = compensationNote(order);
  const when = dateAndTime(order.paid_at ?? order.created_at);

  return (
    <Link
      href={`/orders/${order.order_number}`}
      className="flex flex-col gap-1.5 rounded-lg border border-border bg-surface px-3.5 py-3 shadow-xs hover:border-border-strong"
    >
      <span className="flex items-center justify-between gap-2">
        <span className="truncate font-mono text-xs font-medium text-ink-muted">{order.order_number}</span>
        <span className="text-[15px] font-semibold text-ink">{formatRm(order.final_amount)}</span>
      </span>
      <span className="flex flex-col">
        <span className="truncate text-sm font-semibold text-ink">{order.game?.name ?? "—"}</span>
        <span className="truncate text-xs text-ink-subtle">{order.package_name ?? "—"}</span>
      </span>
      <span className="flex flex-wrap gap-x-3.5 gap-y-1.5 text-xs text-ink-subtle">
        <span className="inline-flex items-center gap-1.5">
          Payment <Badge tone={payment.tone}>{payment.label}</Badge>
        </span>
        <span className="inline-flex items-center gap-1.5">
          Delivery <Badge tone={delivery.tone}>{delivery.label}</Badge>
        </span>
      </span>
      {note && <span className="text-xs text-ink-subtle">{note}</span>}
      <span className="flex justify-between text-xs text-ink-subtle">
        <span>
          {when.date}, {when.time}
        </span>
        {affiliate && order.affiliate_profit != null && <span className="font-semibold text-ink">Earned + {formatRm(order.affiliate_profit)}</span>}
      </span>
    </Link>
  );
}
