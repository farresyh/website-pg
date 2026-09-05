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
  wallet_topup: "Top-up",
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
      not_started: "muted",
      failed: "danger",
      refunded: "warn",
    }[status] ?? "muted"
  );
}

export function subscriptionSeverity(status: string): string {
  return { active: "success", grace: "warn", lapsed: "danger" }[status] ?? "muted";
}

export function humanize(value: string): string {
  return value.replace(/_/g, " ");
}
