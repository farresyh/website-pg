import { test } from "node:test";
import assert from "node:assert/strict";
import { toBasisPoints } from "./campaign-input.ts";

test("a typed share becomes basis points, with or without the % sign", () => {
  assert.equal(toBasisPoints("2"), 200);
  assert.equal(toBasisPoints("2%"), 200);
  assert.equal(toBasisPoints(" 1.5 % "), 150);
  assert.equal(toBasisPoints("0.25"), 25);
});

test("an empty share is no share", () => {
  assert.equal(toBasisPoints(""), 0);
  assert.equal(toBasisPoints("  "), 0);
});

test("an unreadable share is sent as -1 so the server rejects it, never saved as 0%", () => {
  for (const v of ["1,5", "two", "2..5", "-2", "1.234", "%"]) {
    assert.equal(toBasisPoints(v), -1, v);
  }
});
