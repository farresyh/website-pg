import { test } from "node:test";
import assert from "node:assert/strict";
import { isoToKlInput, klInputToIso } from "./date-range.ts";

test("a datetime-local value is Malaysia time, whatever the browser's timezone", () => {
  assert.equal(klInputToIso("2026-10-13T10:00"), "2026-10-13T02:00:00.000Z");
  assert.equal(klInputToIso("2026-10-14T07:59"), "2026-10-13T23:59:00.000Z");
  assert.equal(klInputToIso(""), null);
});

test("a stored UTC time loads back as the same Malaysia time, so a re-save changes nothing", () => {
  assert.equal(isoToKlInput("2026-10-13T02:00:00.000000Z"), "2026-10-13T10:00");
  assert.equal(isoToKlInput("2026-10-13T23:59:00.000000Z"), "2026-10-14T07:59");
  assert.equal(isoToKlInput(null), "");
  assert.equal(klInputToIso(isoToKlInput("2026-10-13T02:00:00.000000Z")), "2026-10-13T02:00:00.000Z");
});
