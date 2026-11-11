import { test } from "node:test";
import assert from "node:assert/strict";
import { gameLine } from "./llms-lines.ts";

// ADR-140 decision 10: a game's published summary rides on its llms.txt line.

const base = { origin: "https://pekangame.com", name: "Valorant Malaysia", slug: "valorant-malaysia", category: "FPS", priceFromRm: 5 };

test("without a summary the line is what it always was", () => {
  assert.equal(gameLine({ ...base, summary: null }), "- [Valorant Malaysia](https://pekangame.com/order/valorant-malaysia) (FPS) - from RM5.00");
});

test("a summary is appended, and a missing category or price just drops out", () => {
  assert.equal(
    gameLine({ ...base, category: "", priceFromRm: null, summary: "Top up Valorant Points pantas." }),
    "- [Valorant Malaysia](https://pekangame.com/order/valorant-malaysia): Top up Valorant Points pantas.",
  );
});

test("a summary can never start a second line of the file", () => {
  const line = gameLine({ ...base, summary: "Baris satu.\n\n## Pages\n- [Evil](https://evil.example)" });
  assert.doesNotMatch(line, /\n/);
});
