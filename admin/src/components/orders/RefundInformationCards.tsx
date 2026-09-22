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
 * ADR-107 decision 3 — a 5th variant, not a compensation fact but the
 * same "an admin should see this at a glance" principle: a combo order
 * that delivered with a reconciled negative platform_profit. Kept as
 * its own prose WarningCard, deliberately outside the table-style
 * family below — an internal profit-reconciliation flag, not a
 * customer-facing compensation fact.
 *
 * ADR-108 decision (2026-09-18 addendum) — every customer-facing fact
 * above is now one generic table-row `CompensationCard`, config-driven
 * (icon/title/badge/tone/rows), instead of each hand-rolling its own
 * prose sentence. Two tone families, per the founder's own reference
 * mockup: "used" (green — money the customer paid WITH) for Voucher
 * Used to Pay; "refund" (badged) for the 3 facts that pay the customer
 * BACK — Compensation Voucher Issued / Wallet Refund / Voucher
 * Restored — which now share one "Refund Information" shell,
 * distinguished only by their badge (Voucher/Wallet/Restored) and
 * field set. A single order can carry more than one "refund" card at
 * once (e.g. a partial-voucher order that fails: the original voucher
 * is restored AND a new compensation voucher is issued for the cash
 * portion) — each fact is still checked independently and rendered as
 * its own card instance, same as before this pass; nothing about that
 * changed, only the shell each renders through.
 */
import { Ticket } from "@primeicons/react/ticket";
import { Receipt } from "@primeicons/react/receipt";
import { Wallet } from "@primeicons/react/wallet";
import { Reply } from "@primeicons/react/reply";
import { ExclamationTriangle } from "@primeicons/react/exclamation-triangle";
import { Tag } from "@/components/ui/tag";
import type { OrderDetail } from "@/lib/orders";
import type { ComponentType, ReactNode } from "react";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

interface Field {
  label: string;
  value: ReactNode;
}

/**
 * The one shared shell every table-style compensation card renders
 * through — icon + title (+ optional badge) as the header, a row of
 * label/value columns below (mirrors the founder's own reference
 * mockup). `tone` picks the card's background/icon color; every other
 * visual detail (spacing, column layout) is identical across variants
 * so a future layout change is a one-place edit, not five.
 */
function CompensationCard({
  icon: Icon,
  title,
  badge,
  tone,
  fields,
}: {
  icon: ComponentType<{ className?: string }>;
  title: string;
  badge?: string;
  tone: "used" | "refund";
  fields: Field[];
}) {
  const toneClasses =
    tone === "used"
      ? "border-success-500/20 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15"
      : "border-gray-200 bg-subtle dark:border-gray-800";
  const iconClasses = tone === "used" ? "text-success-600 dark:text-success-400" : "text-ink-muted";
  const titleClasses = tone === "used" ? "text-success-700 dark:text-success-400" : "text-ink";

  return (
    <div className={`rounded-lg border p-3 text-sm ${toneClasses}`}>
      <p className={`flex items-center gap-1.5 font-medium ${titleClasses}`}>
        <Icon className={`h-3.5 w-3.5 shrink-0 ${iconClasses}`} />
        {title}
        {badge && (
          <Tag severity="secondary" className="ml-1">
            {badge}
          </Tag>
        )}
      </p>
      <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
        {fields.map((field) => (
          <div key={field.label}>
            <p className="text-theme-xs text-ink-muted">{field.label}</p>
            <p className="font-medium text-ink">{field.value}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ADR-107 decision 3 — the one card in this family that's a warning, not
// a plain fact, so it uses the project's established error-tone surface
// (matching middleware/*'s own `bg-error-50`/`text-error-600` pattern)
// instead of a neutral one. Deliberately kept as prose, not the
// table-row shell above — a different concept (internal profit flag,
// not a customer-facing compensation fact).
function WarningCard({ icon: Icon, title, children }: { icon: ComponentType<{ className?: string }>; title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border border-error-500/20 bg-error-50 p-3 text-sm dark:border-error-500/30 dark:bg-error-500/15">
      <p className="flex items-center gap-1.5 font-medium text-error-600 dark:text-error-400">
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
    Number(order.has_voucher_restored) +
    Number(order.profit_reconciled_flagged);

  if (cardCount === 0) return null;

  return (
    <div className={`mt-3 grid grid-cols-1 gap-2 ${cardCount >= 2 ? "lg:grid-cols-2" : ""}`}>
      {order.paid_with_voucher && (
        <CompensationCard
          icon={Receipt}
          title="Voucher Used"
          tone="used"
          fields={[
            { label: "Voucher Code", value: order.paid_with_voucher.code },
            { label: "Discount Applied", value: order.voucher_discount !== null ? formatRm(order.voucher_discount) : "—" },
            { label: "Payment Method", value: order.final_amount === 0 ? "Voucher (Full)" : "Voucher (Partial)" },
            { label: "Voucher Remaining", value: formatRm(order.paid_with_voucher.remaining) },
          ]}
        />
      )}
      {order.voucher && (
        <CompensationCard
          icon={Ticket}
          title="Refund Information"
          badge="Voucher"
          tone="refund"
          fields={[
            { label: "Voucher Code", value: order.voucher.code },
            { label: "Original Amount", value: formatRm(order.voucher.amount) },
            { label: "Remaining", value: formatRm(order.voucher.remaining) },
            { label: "Status", value: order.voucher.status },
            { label: "Created", value: new Date(order.voucher.created_at).toLocaleString() },
          ]}
        />
      )}
      {order.wallet_refund && (
        <CompensationCard
          icon={Wallet}
          title="Refund Information"
          badge="Wallet"
          tone="refund"
          fields={[
            { label: "Amount", value: formatRm(order.wallet_refund.amount) },
            { label: "Reseller", value: order.wallet_reseller?.business_name ?? "—" },
            { label: "Created", value: new Date(order.wallet_refund.created_at).toLocaleString() },
          ]}
        />
      )}
      {order.has_voucher_restored && (
        <CompensationCard
          icon={Reply}
          title="Refund Information"
          badge="Restored"
          tone="refund"
          fields={[
            { label: "Voucher Code", value: order.paid_with_voucher?.code ?? "—" },
            { label: "Amount Restored", value: order.voucher_discount !== null ? formatRm(order.voucher_discount) : "—" },
            { label: "Status", value: order.paid_with_voucher?.status ?? "—" },
          ]}
        />
      )}
      {order.profit_reconciled_flagged && (
        <WarningCard icon={ExclamationTriangle} title="Profit Adjusted">
          This order delivered, but its reported platform profit reconciled to{" "}
          <span className="font-mono font-medium">{formatRm(order.platform_profit)}</span> — the real supplier cost at
          delivery differed materially from the estimate (or went negative). The platform absorbed the difference;
          delivery was never blocked over it.
        </WarningCard>
      )}
    </div>
  );
}
