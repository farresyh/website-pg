import { test } from "node:test";
import assert from "node:assert/strict";
import { tierUpdate } from "./joki-tiers.ts";

const base = { name: "x", rate_rm: 2, express_rate_rm: 3, icon_url: "" };

test("a divisional tier sends divisions and stars, never a Mythic start", () => {
  const sent = tierUpdate({ ...base, id: 1, kind: "divisional", divisions: 5, stars_per_division: 5, mythic_from_stars: null } as never);
  assert.deepEqual(sent, { id: 1, name: "x", rate_rm: 2, express_rate_rm: 3, icon_url: null, divisions: 5, stars_per_division: 5 });
});

test("a Mythic tier sends its Mythic start, never divisions", () => {
  const sent = tierUpdate({ ...base, id: 3, kind: "mythic", divisions: null, stars_per_division: null, mythic_from_stars: 25 } as never);
  assert.deepEqual(sent, { id: 3, name: "x", rate_rm: 2, express_rate_rm: 3, icon_url: null, mythic_from_stars: 25 });
});
