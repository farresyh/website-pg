const PAYMENT_LABELS: Record<string, { label: string; tone: "success" | "warning" | "error" }> = {
  paid: { label: "Paid", tone: "success" },
  pending: { label: "Pending Payment", tone: "warning" },
  failed: { label: "Payment Failed", tone: "error" },
};

const DELIVERY_LABELS: Record<string, { label: string; tone: "success" | "warning" | "error" }> = {
  delivered: { label: "Delivered", tone: "success" },
  processing: { label: "Processing", tone: "warning" },
  // ADR-032: an async supplier accepted the order but hasn't confirmed
  // the final outcome yet — same customer-facing copy as "processing",
  // since to a customer both mean "topup sedang diproses".
  pending: { label: "Processing", tone: "warning" },
  not_started: { label: "Waiting for Payment", tone: "warning" },
  failed: { label: "Delivery Failed", tone: "error" },
};

const TONE_CLASSES: Record<"success" | "warning" | "error", string> = {
  success: "border-ink bg-success text-on-success",
  warning: "border-ink bg-warning text-on-warning",
  error: "border-ink bg-danger text-on-danger",
};

/**
 * `type="payment"` reads Xendit's state, `type="delivery"` reads the
 * supplier's — the two independent state machines ORD-11 describes
 * are shown as two separate badges, never merged into one status.
 */
export default function StatusBadge({ type, status }: { type: "payment" | "delivery"; status: string }) {
  const map = type === "payment" ? PAYMENT_LABELS : DELIVERY_LABELS;
  const entry = map[status] ?? { label: status, tone: "warning" as const };

  return (
    <span
      className={`inline-flex items-center rounded-sm border px-2.5 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide ${TONE_CLASSES[entry.tone]}`}
    >
      {entry.label}
    </span>
  );
}
