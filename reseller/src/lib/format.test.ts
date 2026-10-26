import { test } from "node:test";
import assert from "node:assert/strict";
import { compensationNote, dateAndTime, deliveryBadge, formatMalaysianPhone, paymentBadge } from "./format.ts";

test("formats a Malaysian mobile number and leaves anything else alone", () => {
  assert.equal(formatMalaysianPhone("+601143013150"), "+60 11-4301 3150");
  assert.equal(formatMalaysianPhone("0123456789"), "+60 12-345 6789");
  assert.equal(formatMalaysianPhone("+6531234567"), "+6531234567");
  assert.equal(formatMalaysianPhone("03-1234 5678"), "03-1234 5678");
});

test("status words follow the design system", () => {
  assert.deepEqual(paymentBadge("paid"), { tone: "success", label: "Paid" });
  assert.deepEqual(deliveryBadge("pending"), { tone: "warning", label: "Pending" });
  assert.deepEqual(deliveryBadge("needs_review"), { tone: "review", label: "Needs review" });
  assert.deepEqual(deliveryBadge("something_new"), { tone: "neutral", label: "something new" });
});

test("a failed order says where the money went", () => {
  assert.equal(compensationNote({ wallet_refunded: true }), "Refunded to wallet");
  assert.equal(compensationNote({ has_compensation_voucher: true, has_voucher_restored: true }), "Customer's voucher restored");
  assert.equal(compensationNote({}), null);
});

test("dates are shown in Malaysia time", () => {
  assert.deepEqual(dateAndTime("2026-10-26T16:30:00Z"), { date: "27 Oct 2026", time: "12:30 AM" });
  assert.deepEqual(dateAndTime(null), { date: "—", time: "" });
});
