import type { OrderDetail, OrderResendAttempt } from "./orders.ts";

export interface DeliveryLogEntry {
  id: string;
  date: string;
  type: "initial" | "resend" | "manual_confirm";
  typeLabel: string;
  packageName: string;
  sku: string;
  priceDiffSen: number | null;
  outcome: string;
  triggeredBy: string | null;
  note: string | null;
  response: Record<string, unknown> | null;
}

/** Shared by every row type below: pull a human note out of a raw supplier_response when the row's own `note` field is empty. */
function deriveNoteFromResponse(response: Record<string, unknown> | null | undefined): string | null {
  if (!response) return null;
  if (typeof response.message === "string") return response.message;
  if (response.rc !== undefined) return `rc: ${String(response.rc)}`;
  return null;
}

/**
 * A combo leg's own attempts (ADR-106 addendum 2026-09-21) are keyed by
 * `order_delivery_leg_id` and shown under that leg, oldest first — never
 * in the order-level log, where each leg's `initial` row would collide
 * with the order's single "Initial Delivery".
 */
export function groupLegAttempts(attempts: OrderResendAttempt[]): Map<number, OrderResendAttempt[]> {
  const byLeg = new Map<number, OrderResendAttempt[]>();
  for (const a of attempts) {
    if (a.order_delivery_leg_id == null) continue;
    byLeg.set(a.order_delivery_leg_id, [...(byLeg.get(a.order_delivery_leg_id) ?? []), a]);
  }
  for (const list of byLeg.values()) list.sort((x, y) => x.created_at.localeCompare(y.created_at) || x.id - y.id);
  return byLeg;
}

export function legAttemptLabel(attemptType: OrderResendAttempt["attempt_type"]): string {
  return attemptType === "initial" ? "First attempt" : "Retry";
}

export function buildDeliveryLogEntries(order: OrderDetail | undefined, attempts: OrderResendAttempt[] = []): DeliveryLogEntry[] {
  const resendList = (order?.resend_attempts ?? attempts ?? []).filter((a) => a.order_delivery_leg_id == null);
  // ADR-106 decision 5: a real durable row, once one exists for this
  // order — at most one 'initial' row can ever exist (fulfill()'s own
  // $wasNotStarted guard).
  const initialAttempt = resendList.find((a) => a.attempt_type === "initial") ?? null;
  // Every other row (resend + manual_confirm) renders in the history
  // list below, unchanged in shape from before this ADR.
  const historyList = resendList.filter((a) => a.attempt_type !== "initial");
  const entries: DeliveryLogEntry[] = [];

  // 1. Initial delivery attempt
  if (initialAttempt) {
    // The durable row exists — real timestamp, real package/SKU, real
    // response, not a live guess off Order's own mutable columns.
    entries.push({
      id: "initial-delivery",
      date: initialAttempt.created_at,
      type: "initial",
      typeLabel: "Initial Delivery",
      packageName: initialAttempt.package?.name ?? order?.package?.name ?? "—",
      sku: initialAttempt.package?.supplier_package_ref ?? order?.supplier_product_ref ?? "—",
      priceDiffSen: null,
      outcome: initialAttempt.outcome,
      triggeredBy: initialAttempt.triggered_by ?? "System (Auto)",
      note: initialAttempt.note ?? deriveNoteFromResponse(initialAttempt.supplier_response) ?? "Initial checkout fulfillment",
      response: (initialAttempt.supplier_response as Record<string, unknown>) ?? null,
    });
  } else if (order && (order.delivery_status !== "not_started" || order.paid_at !== null || order.supplier_response !== null)) {
    // No durable row exists (an order created before ADR-106 shipped) —
    // the exact 2026-09-15 bugfix's own honest-fallback synthesis,
    // unchanged: the true initial response is already unrecoverably
    // gone the moment a resend has happened, so this is a "not
    // retained" placeholder rather than a guess.
    const hasResends = historyList.length > 0;

    // 2026-09-15 bugfix: Order.supplier_response/delivery_status are the
    // single, mutable "current state" fields — overwritten by every
    // resend. For an order that's never been resent they ARE the
    // initial attempt's true state (including through an async
    // pending→resolved transition), so showing them here is accurate.
    // But the moment a resend has happened, they reflect the LATEST
    // attempt, not the original one — the true initial response is
    // genuinely gone. Showing them under an "Initial Delivery" label
    // with a guessed outcome would be lying twice over (found live: a
    // Sukses/Delivered response mislabeled outcome=failed) — an honest
    // "not retained" placeholder beats a wrong guess.
    const initialOutcome = hasResends ? "unknown" : order.delivery_status;
    const initialNote = hasResends ? null : deriveNoteFromResponse(order.supplier_response as Record<string, unknown> | null);

    entries.push({
      id: "initial-delivery",
      date: order.paid_at ?? order.created_at,
      type: "initial",
      typeLabel: "Initial Delivery",
      packageName: order.package?.name ?? "—",
      sku: order.supplier_product_ref ?? order.package?.supplier_package_ref ?? "—",
      priceDiffSen: null,
      outcome: initialOutcome,
      triggeredBy: "System (Auto)",
      note: hasResends ? "Original response not retained — superseded by a later resend." : (initialNote ?? "Initial checkout fulfillment"),
      response: hasResends ? null : ((order.supplier_response as Record<string, unknown>) ?? null),
    });
  }

  // 2. Resend + manual-confirm history
  for (const attempt of historyList) {
    const attemptNote = attempt.note ?? deriveNoteFromResponse(attempt.supplier_response);

    entries.push({
      id: `${attempt.attempt_type}-${attempt.id}`,
      date: attempt.created_at,
      type: attempt.attempt_type === "manual_confirm" ? "manual_confirm" : "resend",
      typeLabel: attempt.attempt_type === "manual_confirm" ? "Manual Confirmation" : "Resend Delivery",
      packageName: attempt.package?.name ?? order?.package?.name ?? "—",
      sku: attempt.package?.supplier_package_ref ?? order?.supplier_product_ref ?? "—",
      priceDiffSen: attempt.price_diff_sen,
      outcome: attempt.outcome,
      triggeredBy: attempt.triggered_by ?? "Admin",
      note: attemptNote ?? "—",
      response: (attempt.supplier_response as Record<string, unknown>) ?? null,
    });
  }

  return entries;
}
