import { test } from "node:test";
import assert from "node:assert/strict";
import { claimByDate, claimQueue, currentKlPeriod, formatRebatePeriod, rebateEntryLabel, type RebateMonth } from "./rebate.ts";

function month(states: string[], adjustment: RebateMonth["adjustment"] = null): RebateMonth {
  return {
    period: "2026-12",
    finalised: false,
    claim_deadline: "2027-02-01T00:00:00+08:00",
    sales_sen: 0,
    steps: states.map((state, i) => ({ step: i + 1, threshold_sen: (i + 1) * 50000, reward_sen: 100, state: state as "locked", paid_sen: null, adjusted: false })),
    adjustment,
    next_threshold_sen: null,
    remaining_sen: null,
  };
}

test("claims run in step order, and the adjustment last", () => {
  assert.deepEqual(claimQueue(month(["claimed", "claimable", "claimable", "locked"])), [2, 3]);
  assert.deepEqual(claimQueue(month(["claimed", "claimed"], { amount_sen: 50, state: "claimable" })), [0]);
  assert.deepEqual(claimQueue(month(["claimable"], { amount_sen: 50, state: "claimable" })), [1, 0]);
  assert.deepEqual(claimQueue(month(["expired", "locked"], { amount_sen: 50, state: "expired" })), []);
});

test("the claim deadline is shown as the last day to claim, in Malaysia time", () => {
  assert.equal(claimByDate("2027-02-01T00:00:00+08:00"), "31 Jan 2027");
  assert.equal(claimByDate("2027-01-01T00:00:00+08:00"), "31 Dec 2026");
});

test("a rebate statement line names its step and month", () => {
  assert.equal(formatRebatePeriod("2026-12"), "December 2026");
  assert.equal(rebateEntryLabel({ period: "2026-11", kind: "step", step: 4, threshold_sen: 500000 }), "RM5,000.00 step (November 2026)");
  assert.equal(rebateEntryLabel({ period: "2026-11", kind: "adjustment", step: 0, threshold_sen: null }), "Monthly adjustment (November 2026)");
});

test("this month is the Malaysia month, which starts 8 hours before UTC's", () => {
  assert.equal(currentKlPeriod(new Date("2026-11-30T16:30:00Z")), "2026-12");
  assert.equal(currentKlPeriod(new Date("2026-11-30T15:59:00Z")), "2026-11");
});
