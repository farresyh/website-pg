import { test } from "node:test";
import assert from "node:assert/strict";
import { busyWindows, describeAttention, formatHourWindow, summarySentence } from "./dashboard-briefing.ts";

const base = { periodLabel: "Last 7 days", previousLabel: "the 7 days before", sales: 486240, orders: 312, topGame: null, lastPaidAt: null, paymentsOff: false };

test("the summary names sales, orders and the change", () => {
  assert.equal(
    summarySentence({ ...base, change: { pct: 18.4, direction: "up" }, topGame: { name: "Mobile Legends", sales: 214060 } }),
    "Last 7 days: RM 4,862.40 from 312 orders, up 18.4% on the 7 days before. Mobile Legends led with RM 2,140.60.",
  );
});

test("an empty period says why instead of showing a bare zero", () => {
  assert.equal(
    summarySentence({ ...base, periodLabel: "Today", sales: 0, orders: 0, change: null, lastPaidAt: "2026-10-10T18:00:00+08:00", paymentsOff: true }),
    "No paid orders today. Last order 10 Oct 2026. Storefront payments are off.",
  );
});

test("no previous data means no change clause", () => {
  assert.equal(summarySentence({ ...base, orders: 1, sales: 3670, change: { pct: null, direction: "new" } }), "Last 7 days: RM 36.70 from 1 order.");
});

test("the busiest 3-hour window wraps past midnight", () => {
  const counts = Array(24).fill(0);
  counts[23] = 5;
  counts[0] = 4;
  counts[1] = 3;
  counts[12] = 6;
  const w = busyWindows(counts);
  assert.deepEqual(w?.peak, { start: 23, orders: 12, pct: 66.7 });
  assert.equal(formatHourWindow(23, 3), "11 PM–2 AM");
  assert.equal(formatHourWindow(20, 3), "8–11 PM");
  assert.equal(busyWindows(Array(24).fill(0)), null);
});

test("supplier group items point at the screen that fixes them (ADR-125)", () => {
  assert.deepEqual(describeAttention({ key: "supplier_posts_not_delivered", level: "red", count: 2, subject: null }), {
    badge: "Supplier group",
    tone: "danger",
    text: "2 orders not posted to the supplier",
    href: "/admin/manual-queue",
  });
  assert.equal(describeAttention({ key: "manual_supplier_without_group", level: "amber", count: 1, subject: null }).text, "1 active supplier with no WhatsApp group");
});
