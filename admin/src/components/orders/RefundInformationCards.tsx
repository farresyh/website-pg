/**
 * ADR-102 decision 11 — independent "Refund Information" card
 * variants, each rendered only when its underlying fact exists on the
 * order, replacing the old single-line "Voucher X already issued" /
 * "Already refunded to Y's wallet" mentions. An admin unfamiliar with
 * the platform's internals should be able to see at a glance what
 * happened to an order's money, not infer it from a status tag.
 *
 * ADR-024 addendum (2026-09-17, restore-only) — a 4th variant: a
 * full-cover-by-voucher order restores its original voucher's balance
 * but mints no new one, so `order.voucher` stays null forever for it.
 * Without this card, that order's detail page would show nothing at
 * all about its resolution, unlike every other compensated order.
 *
 * ADR-104 (visual pass): icons swap from emoji to the shared
 * @primeicons/react set (matches decision 6's icon system elsewhere on
 * this page); the wrapper becomes a responsive grid — 2 columns when 2+
 * of these cards are visible at once (matches the artifact's own
 * side-by-side layout for that case), 1 column (full width) when only
 * one applies, stacking on narrow viewports either way. Labels, data,
 * and conditions are all unchanged from before this pass.
 */
import { Ticket } from "@primeicons/react/ticket";
import { Receipt } from "@primeicons/react/receipt";
import { Wallet } from "@primeicons/react/wallet";
import { Reply } from "@primeicons/react/reply";
import type { OrderDetail } from "@/lib/orders";
import type { ComponentType, ReactNode } from "react";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function Card({ icon: Icon, title, children }: { icon: ComponentType<{ className?: string }>; title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-subtle p-3 text-sm dark:border-gray-800">
      <p className="flex items-center gap-1.5 font-medium text-ink">
        <Icon className="h-3.5 w-3.5 shrink-0" />
        {title}
      </p>
      <div className="mt-1 text-ink-muted">{children}</div>
    </div>
  );
}

export default function RefundInformationCards({ order }: { order: OrderDetail }) {
  const cardCount =
    Number(!!order.paid_with_voucher) +
    Number(!!order.voucher) +
    Number(!!order.wallet_refund) +
    Number(order.has_voucher_restored);

  if (cardCount === 0) return null;

  return (
    <div className={`mt-3 grid grid-cols-1 gap-2 ${cardCount >= 2 ? "lg:grid-cols-2" : ""}`}>
      {order.paid_with_voucher && (
        <Card icon={Receipt} title="Voucher Used to Pay">
          <span className="font-medium text-ink">{order.paid_with_voucher.code}</span>
          {" — "}
          {formatRm(order.paid_with_voucher.amount)} original, {formatRm(order.paid_with_voucher.remaining)} remaining (
          {order.paid_with_voucher.status})
        </Card>
      )}
      {order.voucher && (
        <Card icon={Ticket} title="Compensation Voucher Issued">
          <span className="font-medium text-ink">{order.voucher.code}</span>
          {" — "}
          {formatRm(order.voucher.amount)} issued, {formatRm(order.voucher.remaining)} remaining ({order.voucher.status})
        </Card>
      )}
      {order.wallet_refund && (
        <Card icon={Wallet} title="Wallet Refund">
          {formatRm(order.wallet_refund.amount)} refunded to{" "}
          <span className="font-medium text-ink">{order.wallet_reseller?.business_name ?? "—"}</span>
          &apos;s wallet on {new Date(order.wallet_refund.created_at).toLocaleString()}.
        </Card>
      )}
      {order.has_voucher_restored && (
        <Card icon={Reply} title="Voucher Restored">
          This order was fully covered by voucher
          {order.paid_with_voucher && (
            <>
              {" "}
              <span className="font-medium text-ink">{order.paid_with_voucher.code}</span>
            </>
          )}
          {order.voucher_discount !== null && <> — {formatRm(order.voucher_discount)} given back to it</>}. No new
          voucher was issued: its cash portion was RM0.00.
        </Card>
      )}
    </div>
  );
}
