import { test } from "node:test";
import assert from "node:assert/strict";
import { formatMalaysianPhone } from "./format.ts";

test("formats a Malaysian mobile number and leaves anything else alone", () => {
  assert.equal(formatMalaysianPhone("+601143013150"), "+60 11-4301 3150");
  assert.equal(formatMalaysianPhone("0123456789"), "+60 12-345 6789");
  assert.equal(formatMalaysianPhone("+6531234567"), "+6531234567");
  assert.equal(formatMalaysianPhone("03-1234 5678"), "03-1234 5678");
});
