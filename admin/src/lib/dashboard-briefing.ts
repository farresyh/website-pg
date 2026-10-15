/**
 * ADR-104 2026-10-15 — the Dashboard's wording, kept pure so it can be
 * tested: the summary sentence, the busy-hours peak, and each status item's
 * label and link. Every figure comes from the backend; this only phrases it.
 */

export interface AttentionItem {
  key: string;
  level: "red" | "amber" | "neutral";
  count: number;
  subject: string | null;
}

/** Badge word + tone, the item's text, and the screen that fixes it (null: the detail is in the System details panel). */
export function describeAttention(item: AttentionItem): { badge: string | null; tone: "danger" | "review" | "warning" | null; text: string; href: string | null } {
  const n = item.count;
  const orders = `${n} ${n === 1 ? "order" : "orders"}`;
  switch (item.key) {
    case "supplier_circuit_open":
      return { badge: "Supplier down", tone: "danger", text: item.subject ?? "Supplier", href: "/admin/balance" };
    case "scheduler_critical_stale":
      return { badge: "Scheduler", tone: "danger", text: `${n} critical ${n === 1 ? "task" : "tasks"} late`, href: null };
    case "queue_failed":
      return { badge: "Queue", tone: "danger", text: `${n} failed ${n === 1 ? "job" : "jobs"}`, href: null };
    case "stuck_orders":
      return { badge: "Stuck", tone: "danger", text: orders, href: "/admin/orders?status=processing" };
    case "needs_action_orders":
      return { badge: "Need action", tone: "danger", text: orders, href: "/admin/orders?status=need_action" };
    case "needs_review":
      return { badge: "Needs review", tone: "review", text: orders, href: "/admin/orders?status=needs_review" };
    case "reseller_bot_disconnected":
      return { badge: "Disconnected", tone: "danger", text: "Reseller Bot", href: "/admin/resellers" };
    case "supplier_low_balance":
      return { badge: "Low balance", tone: "warning", text: item.subject ?? "Supplier", href: "/admin/balance" };
    case "supplier_drift":
      return { badge: "Funding drift", tone: "warning", text: item.subject ?? "Supplier", href: "/admin/accounting/suppliers" };
    case "withdrawals_to_process":
      return { badge: "Withdrawals", tone: "warning", text: `${n} to process`, href: "/admin/withdrawals" };
    case "price_changes_pending":
      return { badge: "Price sync", tone: "warning", text: `${n} ${n === 1 ? "change" : "changes"} to review`, href: "/middleware/price-sync" };
    case "whatsapp_links_pending":
      return { badge: "WhatsApp", tone: "warning", text: `${n} ${n === 1 ? "group" : "groups"} to link`, href: "/admin/resellers" };
    case "backup_failed":
      return { badge: "Backup", tone: "warning", text: "Last backup failed", href: "/middleware/backups" };
    case "customer_whatsapp_disconnected":
      return { badge: "Disconnected", tone: "warning", text: "Customer WhatsApp", href: "/admin/settings" };
    case "payments_off":
      return { badge: null, tone: null, text: "Storefront payments are off", href: "/middleware/payment-methods" };
    case "reviews_pending":
      return { badge: null, tone: null, text: `${n} ${n === 1 ? "review" : "reviews"} pending`, href: "/admin/reviews?status=pending" };
    default:
      return { badge: null, tone: null, text: item.key, href: null };
  }
}

function rm(sen: number): string {
  return `RM ${(sen / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function longDate(iso: string): string {
  return new Date(iso).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric", timeZone: "Asia/Kuala_Lumpur" });
}

/**
 * The header sentence. `periodLabel` is "Today" or "Last 7 days";
 * `previousLabel` is what the change is against ("yesterday so far").
 */
export function summarySentence(input: {
  periodLabel: string;
  previousLabel: string;
  sales: number;
  orders: number;
  change: { pct: number | null; direction: "up" | "down" | "flat" | "new" } | null;
  topGame: { name: string; sales: number } | null;
  lastPaidAt: string | null;
  paymentsOff: boolean;
}): string {
  const parts: string[] = [];
  if (input.orders === 0) {
    parts.push(`No paid orders ${input.periodLabel === "Today" ? "today" : `in the ${input.periodLabel.toLowerCase()}`}.`);
    if (input.lastPaidAt) parts.push(`Last order ${longDate(input.lastPaidAt)}.`);
  } else {
    const c = input.change;
    const trend =
      c && (c.direction === "up" || c.direction === "down") && c.pct !== null
        ? `, ${c.direction} ${c.pct.toFixed(1)}% on ${input.previousLabel}`
        : c?.direction === "flat"
          ? `, level with ${input.previousLabel}`
          : "";
    parts.push(`${input.periodLabel}: ${rm(input.sales)} from ${input.orders.toLocaleString()} ${input.orders === 1 ? "order" : "orders"}${trend}.`);
    if (input.topGame) parts.push(`${input.topGame.name} led with ${rm(input.topGame.sales)}.`);
  }
  if (input.paymentsOff) parts.push("Storefront payments are off.");
  return parts.join(" ");
}

/** "9 PM" style hour label, 0–24 (24 = midnight). */
function hourLabel(hour: number): string {
  const h = hour % 24;
  return h === 0 ? "12 AM" : h === 12 ? "12 PM" : h < 12 ? `${h} AM` : `${h - 12} PM`;
}

/** "8–11 PM", or "10 PM–1 AM" when the window crosses noon or midnight. */
export function formatHourWindow(start: number, length: number): string {
  const a = hourLabel(start);
  const b = hourLabel(start + length);
  const [an, as] = a.split(" ");
  const [bn, bs] = b.split(" ");
  return as === bs ? `${an}–${bn} ${bs}` : `${a}–${b}`;
}

/**
 * The busiest and quietest `length`-hour windows (wrapping past midnight),
 * by paid orders. Null with no orders. Ties go to the earlier hour.
 */
export function busyWindows(counts: number[], length = 3): { peak: { start: number; orders: number; pct: number }; quiet: { start: number } } | null {
  const total = counts.reduce((a, b) => a + b, 0);
  if (total === 0) return null;
  const sums = counts.map((_, start) => Array.from({ length }, (_, k) => counts[(start + k) % 24]).reduce((a, b) => a + b, 0));
  const peakStart = sums.indexOf(Math.max(...sums));
  const quietStart = sums.indexOf(Math.min(...sums));
  return {
    peak: { start: peakStart, orders: sums[peakStart], pct: Math.round((sums[peakStart] / total) * 1000) / 10 },
    quiet: { start: quietStart },
  };
}
