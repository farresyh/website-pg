import { test } from "node:test";
import assert from "node:assert/strict";
import { spacedBrandName } from "./brand-name.ts";

// ADR-140 decision 4: people type "pekan game" and "pekgam" for PekanGame, so
// the primary brand's Organization data also names the spaced form. Computed
// from the store name: no field, nothing to drift.

test("a CamelCase name is split at its word boundaries", () => {
  assert.equal(spacedBrandName("PekanGame"), "Pekan Game");
  assert.equal(spacedBrandName("OhahaStore"), "Ohaha Store");
  assert.equal(spacedBrandName("PekanGameID"), "Pekan Game ID");
});

test("a name that does not split gives nothing, so no alternate is invented", () => {
  for (const name of ["Pekan Game", "PUBG", "pekangame", "Pekan", ""]) {
    assert.equal(spacedBrandName(name), null, JSON.stringify(name));
  }
});
