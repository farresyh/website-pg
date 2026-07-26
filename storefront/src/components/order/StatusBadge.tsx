const PAYMENT_LABELS: Record<string, { label: string; tone: "success" | "warning" | "error" }> = {
  paid: { label: "Paid", tone: "success" },
  pending: { label: "Pending Payment", tone: "warning" },
  failed: { label: "Payment Failed", tone: "error" },
};

const DELIVERY_LABELS: Record<string, { label: string; tone: "success" | "warning" | "error" }> = {
  delivered: { label: "Delivered", tone: "success" },
  processing: { label: "Processing", tone: "warning" },
  not_started: { label: "Waiting for Payment", tone: "warning" },
  failed: { label: "Delivery Failed", tone: "error" },
};

const TONE_CLASSES: Record<"success" | "warning" | "error", string> = {
  success: "border-brand-light/40 bg-brand-dark/40 text-brand-light",
  warning: "border-amber/40 bg-amber/10 text-amber",
  error: "border-error/40 bg-error/10 text-error",
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
    <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[12px] font-bold ${TONE_CLASSES[entry.tone]}`}>
      {entry.label}
    </span>
  );
}
