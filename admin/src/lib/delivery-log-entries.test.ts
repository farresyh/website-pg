import { test } from "node:test";
import assert from "node:assert/strict";
import { buildDeliveryLogEntries, groupLegAttempts, legAttemptLabel, costVsCheckoutByAttempt } from "./delivery-log-entries.ts";
import type { OrderDetail, OrderResendAttempt } from "./orders.ts";

const order = { delivery_status: "delivered", paid_at: "2026-09-22T00:00:00Z", created_at: "2026-09-22T00:00:00Z", supplier_response: null, package: { name: "Combo" } } as unknown as OrderDetail;

const legAttempt = (id: number, leg: number): OrderResendAttempt => ({
  id,
  order_delivery_leg_id: leg,
  attempt_type: "initial",
  package: { id: leg, name: `Component ${leg}` },
  cost_price_sen: 100,
  standard_selling_price_sen: 120,
  price_diff_sen: null,
  outcome: "success",
  note: null,
  triggered_by: null,
  created_at: `2026-09-22T00:00:0${leg}Z`,
});

const comboOrder = (attempts: OrderResendAttempt[]) => ({ ...order, resend_attempts: attempts }) as OrderDetail;

// ADR-106 addendum: a combo's 3 legs each write their own `initial` row.
// They belong under their leg, not in the order-level log, where they
// used to swallow each other (only one survived, mislabelled as the
// order's "Initial Delivery").
test("leg attempts stay out of the order-level log", () => {
  const entries = buildDeliveryLogEntries(comboOrder([1, 2, 3].map((n) => legAttempt(n, n))));
  assert.equal(entries.some((e) => e.packageName.startsWith("Component")), false);
});

test("the order's own initial row is untouched by leg rows", () => {
  const orderLevel: OrderResendAttempt = { ...legAttempt(9, 1), order_delivery_leg_id: null, package: { id: 1, name: "Combo" } };
  const entries = buildDeliveryLogEntries(comboOrder([orderLevel, legAttempt(1, 1), legAttempt(2, 2)]));
  assert.deepEqual(entries.map((e) => e.packageName), ["Combo"]);
});

test("every leg's attempts are grouped under it, oldest first", () => {
  const retry: OrderResendAttempt = { ...legAttempt(4, 2), attempt_type: "retry", created_at: "2026-09-22T01:00:00Z" };
  const byLeg = groupLegAttempts([retry, legAttempt(1, 1), legAttempt(2, 2), legAttempt(3, 3)]);
  assert.deepEqual([...byLeg.keys()].sort(), [1, 2, 3]);
  assert.deepEqual(byLeg.get(2)!.map((a) => a.id), [2, 4]);
  assert.equal(legAttemptLabel("retry"), "Retry");
  assert.equal(legAttemptLabel("initial"), "First attempt");
});

// ADR-106 2026-11-02 addendum: the backend's per-unit "Cost vs checkout"
// lands on the attempt row that delivered the unit, and nowhere else.
test("cost vs checkout sits on the delivering row only", () => {
  const initial: OrderResendAttempt = { ...legAttempt(10, 1), order_delivery_leg_id: null, outcome: "failed" };
  const resend: OrderResendAttempt = { ...initial, id: 11, attempt_type: "resend", outcome: "success", price_diff_sen: 8 };
  const detail = { ...comboOrder([resend, initial]), cost_vs_checkout: [{ attempt_id: 11, order_delivery_leg_id: null, cost_vs_checkout_sen: 7, cost_basis: "real" }] } as OrderDetail;
  const entries = buildDeliveryLogEntries(detail);
  assert.deepEqual(entries.map((e) => e.costVsCheckout), [null, { sen: 7, basis: "real" }]);
  assert.deepEqual(costVsCheckoutByAttempt(detail.cost_vs_checkout).get(11), { sen: 7, basis: "real" });
});

test("no backend figure means no figure, never the old catalog diff", () => {
  const resend: OrderResendAttempt = { ...legAttempt(11, 1), order_delivery_leg_id: null, attempt_type: "resend", price_diff_sen: 8 };
  assert.deepEqual(buildDeliveryLogEntries(comboOrder([resend])).map((e) => e.costVsCheckout), [null, null]);
});
