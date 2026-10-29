/** All money crosses the API as an integer in sen (RM 1.00 = 100). */
export function formatRm(sen: number): string {
  const rm = sen / 100;
  const sign = rm < 0 ? "-" : "";
  return `${sign}RM ${Math.abs(rm).toLocaleString("en-MY", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-MY", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

export function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-MY", { dateStyle: "medium" });
}

const LEDGER_TYPE_LABELS: Record<string, string> = {
  order_profit: "Order margin",
  withdrawal: "Withdrawal",
  reseller_tier_fee: "Wholesale-tier fee",
  adjustment: "Adjustment",
  // ADR-073 — a Reseller (wallet) account's own ledger entry types.
  wallet_topup: "Balance added",
  wallet_debit: "Order",
  wallet_refund: "Refund",
};

export function ledgerTypeLabel(type: string): string {
  return LEDGER_TYPE_LABELS[type] ?? type.replace(/_/g, " ");
}

export function paymentSeverity(status: string): string {
  return { paid: "success", pending: "warn", failed: "danger" }[status] ?? "muted";
}

export function deliverySeverity(status: string): string {
  return (
    {
      delivered: "success",
      processing: "info",
      pending: "info",
      needs_review: "warn",
      partially_delivered: "warn",
      not_started: "muted",
      failed: "danger",
    }[status] ?? "muted"
  );
}

export function subscriptionSeverity(status: string): string {
  return { active: "success", grace: "warn", lapsed: "danger" }[status] ?? "muted";
}

export function humanize(value: string): string {
  return value.replace(/_/g, " ");
}

/** "+601143013150" → "+60 11-4301 3150"; anything not a Malaysian mobile number is returned as given. */
export function formatMalaysianPhone(raw: string): string {
  const digits = raw.replace(/\D/g, "");
  const rest = digits.startsWith("60") ? digits.slice(2) : digits.startsWith("0") ? digits.slice(1) : null;
  if (!rest || !/^1\d{8,9}$/.test(rest)) return raw;
  return `+60 ${rest.slice(0, 2)}-${rest.slice(2, -4)} ${rest.slice(-4)}`;
}

export type StatusBadge = { tone: "success" | "warning" | "danger" | "review" | "neutral" | "info"; label: string };

/** ADR-104: payment is Paid, Pending or Failed, each with its own colour. */
export function paymentBadge(status: string): StatusBadge {
  return (
    { paid: { tone: "success", label: "Paid" }, pending: { tone: "warning", label: "Pending" }, failed: { tone: "danger", label: "Failed" } } as const
  )[status as "paid"] ?? { tone: "neutral", label: humanize(status) };
}

/** ADR-104 delivery words. "pending" (waiting on the supplier) reads as Pending; a partner is never told which supplier. */
export function deliveryBadge(status: string): StatusBadge {
  const map: Record<string, StatusBadge> = {
    not_started: { tone: "neutral", label: "Not started" },
    processing: { tone: "warning", label: "Processing" },
    pending: { tone: "warning", label: "Pending" },
    needs_review: { tone: "review", label: "Needs review" },
    partially_delivered: { tone: "review", label: "Partly delivered" },
    delivered: { tone: "success", label: "Delivered" },
    failed: { tone: "danger", label: "Failed" },
  };
  return map[status] ?? { tone: "neutral", label: humanize(status) };
}

/** What happened to the money on a failed order, from the existing compensation fields (ADR-112 decision 4). */
export function compensationNote(order: {
  wallet_refunded?: boolean;
  has_compensation_voucher?: boolean;
  has_voucher_restored?: boolean;
}): string | null {
  if (order.wallet_refunded) return "Refunded to Purchase Balance";
  if (order.has_voucher_restored) return "Customer's voucher restored";
  if (order.has_compensation_voucher) return "Customer got a store voucher";
  return null;
}

/** "Good morning" before noon, "Good afternoon" before 6 PM, then "Good evening" — the viewer's own clock. */
export function greeting(hour: number): string {
  return hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
}

/** "26 Oct 2026" and "4:12 PM" as two lines, in Malaysia time. */
export function dateAndTime(iso: string | null): { date: string; time: string } {
  if (!iso) return { date: "—", time: "" };
  const d = new Date(iso);
  return {
    date: d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", timeZone: "Asia/Kuala_Lumpur" }),
    time: d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", timeZone: "Asia/Kuala_Lumpur" }),
  };
}

/**
 * One ledger row in words: its type badge and a short line about where the
 * money came from or went, from the row's own fields (ADR-073 reference
 * types). An admin's reason, when present, wins.
 */
export function ledgerRow(entry: { type: string; amount: number; reference_type: string | null; reason: string | null }): {
  badge: StatusBadge;
  detail: string;
} {
  const label = ledgerTypeLabel(entry.type);
  const credit = entry.amount > 0;
  const map: Record<string, { tone: StatusBadge["tone"]; detail: string }> = {
    wallet_topup: entry.reference_type === "wallet_topup_attempt"
      ? { tone: "info", detail: "Paid online through CHIP" }
      : { tone: "info", detail: "Credited by PekanGame" },
    wallet_debit: { tone: "neutral", detail: "Paid from Purchase Balance" },
    wallet_refund: { tone: "success", detail: "Order not delivered, amount returned" },
    order_profit: credit ? { tone: "success", detail: "Your margin on a delivered order" } : { tone: "review", detail: "Correction to an order's margin" },
    withdrawal: { tone: "neutral", detail: "Sent to your bank" },
    reseller_tier_fee: { tone: "neutral", detail: "Monthly wholesale-tier fee" },
    adjustment: { tone: "review", detail: "Adjustment by PekanGame" },
  };
  const known = map[entry.type] ?? { tone: "neutral" as const, detail: "" };
  return { badge: { tone: known.tone, label }, detail: entry.reason?.trim() || known.detail };
}

/** A signed amount for a ledger: "+ RM 500.00" or "− RM 6.21". */
export function signedRm(sen: number): string {
  return `${sen < 0 ? "−" : "+"} ${formatRm(Math.abs(sen))}`;
}

/** Ringgit typed by a person → sen, or null when it isn't a positive amount with at most 2 decimals. */
export function toSen(value: string): number | null {
  if (!/^\d+(\.\d{1,2})?$/.test(value.trim())) return null;
  const sen = Math.round(Number(value) * 100);
  return sen > 0 ? sen : null;
}
