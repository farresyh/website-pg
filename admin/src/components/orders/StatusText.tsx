import type { ReactNode } from "react";
import type { OrderListItem } from "@/lib/orders";

/**
 * The order status renderers shared by Orders and Admin → Joki (ADR-126
 * decision 23 uses /orders as the visual benchmark), moved out of
 * `app/admin/orders/page.tsx` unchanged.
 */
export const paymentStatusSeverity: Record<OrderListItem["payment_status"], "warn" | "success" | "danger"> = {
  pending: "warn",
  paid: "success",
  failed: "danger",
};

export const deliveryStatusSeverity: Record<OrderListItem["delivery_status"], "secondary" | "warn" | "success" | "danger" | "info" | "review"> = {
  not_started: "secondary",
  processing: "warn",
  delivered: "success",
  failed: "danger",
  // ADR-104 decision 3: the artifact's own distinct "review" token, not
  // "warn" — closes the exact ADR-032 gap the comment below already
  // flagged (needs_review and processing used to share one color).
  needs_review: "review",
  // ADR-032 — a distinct color from "processing" so an admin can tell
  // at a glance this is waiting on an async supplier, not a normal
  // in-flight delivery attempt.
  pending: "info",
  // ADR-094 decision 28 — final, but needs compensating for its undelivered part.
  partially_delivered: "warn",
};

// ADR-104: payment/delivery status specifically render as a dot + plain
// text (no pill background), matching the artifact's own OrderDetailFailed/
// OrdersPage mockups — every other Tag usage on this page (compensation
// badges, pricing-basis badges, combo-leg/outcome tags) keeps the shared
// `Tag` component's pill styling untouched. Same severity values already
// computed above, just a different renderer — no status/label/data change.
const STATUS_TEXT_COLOR: Record<string, string> = {
  default: "text-cyan-ink",
  secondary: "text-neutral-ink",
  info: "text-info-ink",
  success: "text-success-ink",
  warn: "text-warning-ink",
  danger: "text-danger-ink",
  review: "text-review-ink",
};

export function StatusText({ severity, children }: { severity: string; children: ReactNode }) {
  const color = STATUS_TEXT_COLOR[severity] ?? "text-ink-muted";
  return (
    <span className={`inline-flex items-center gap-1.5 text-sm font-medium ${color}`}>
      <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />
      {children}
    </span>
  );
}

