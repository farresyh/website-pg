/**
 * ADR-102 decision 11 — three independent "Refund Information" card
 * variants, each rendered only when its underlying fact exists on the
 * order, replacing the old single-line "Voucher X already issued" /
 * "Already refunded to Y's wallet" mentions. An admin unfamiliar with
 * the platform's internals should be able to see at a glance what
 * happened to an order's money, not infer it from a status tag.
 */
import type { OrderDetail } from "@/lib/orders";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function Card({ emoji, title, children }: { emoji: string; title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-white/5">
      <p className="font-medium text-gray-700 dark:text-gray-300">
        {emoji} {title}
      </p>
      <div className="mt-1 text-gray-600 dark:text-gray-400">{children}</div>
    </div>
  );
}

export default function RefundInformationCards({ order }: { order: OrderDetail }) {
  if (!order.paid_with_voucher && !order.voucher && !order.wallet_refund) return null;

  return (
    <div className="mt-3 space-y-2">
      {order.paid_with_voucher && (
        <Card emoji="🎫" title="Voucher Used to Pay">
          <span className="font-medium text-gray-800 dark:text-white/90">{order.paid_with_voucher.code}</span>
          {" — "}
          {formatRm(order.paid_with_voucher.amount)} original, {formatRm(order.paid_with_voucher.remaining)} remaining (
          {order.paid_with_voucher.status})
        </Card>
      )}
      {order.voucher && (
        <Card emoji="🎟️" title="Compensation Voucher Issued">
          <span className="font-medium text-gray-800 dark:text-white/90">{order.voucher.code}</span>
          {" — "}
          {formatRm(order.voucher.amount)} issued, {formatRm(order.voucher.remaining)} remaining ({order.voucher.status})
        </Card>
      )}
      {order.wallet_refund && (
        <Card emoji="💰" title="Wallet Refund">
          {formatRm(order.wallet_refund.amount)} refunded to{" "}
          <span className="font-medium text-gray-800 dark:text-white/90">{order.wallet_reseller?.business_name ?? "—"}</span>
          &apos;s wallet on {new Date(order.wallet_refund.created_at).toLocaleString()}.
        </Card>
      )}
    </div>
  );
}
