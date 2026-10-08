import { test } from "node:test";
import assert from "node:assert/strict";
import { autoBucket, bucketRows, bucketStart, marginPct } from "./report-buckets.ts";

test("weeks start Monday, months on the 1st", () => {
  assert.equal(bucketStart("2026-10-04", "week"), "2026-09-28"); // Sunday
  assert.equal(bucketStart("2026-10-05", "week"), "2026-10-05"); // Monday
  assert.equal(bucketStart("2026-10-31", "month"), "2026-10-01");
});

test("bucket margin is Σ profit ÷ Σ sales, not an average of daily margins", () => {
  const days = [
    { date: "2026-10-05", sales: 1000, platform_profit: 500 }, // 50%
    { date: "2026-10-06", sales: 9000, platform_profit: 900 }, // 10%
  ];
  const [week] = bucketRows(days, "week", ["sales", "platform_profit"]);
  assert.deepEqual(week, { date: "2026-10-05", sales: 10000, platform_profit: 1400 });
  assert.equal(marginPct(week.platform_profit, week.sales), 14); // the daily average would say 30
  assert.equal(marginPct(0, 0), null);
});

test("All-time auto bucket thresholds (R17)", () => {
  assert.equal(autoBucket(62), "day");
  assert.equal(autoBucket(63), "week");
  assert.equal(autoBucket(366), "week");
  assert.equal(autoBucket(367), "month");
});
