import { test } from "node:test";
import assert from "node:assert/strict";
import { resolveGameMeta, resolveSiteMeta, type SeoTextSettings } from "./seo-meta.ts";
import { pageMeta } from "./page-meta.ts";

const EMPTY: SeoTextSettings = {
  default_meta_title: null,
  default_meta_description: null,
  meta_title_template: null,
  meta_description_template: null,
};

// ADR-140 decision 1: with the admin's default fields empty, the home page
// reads the registry, not a hardcoded English sentence.
test("an empty default falls to the registry's home entry, with the store name", () => {
  assert.deepEqual(resolveSiteMeta(EMPTY, "PekanGame"), pageMeta("home", "PekanGame"));
  assert.doesNotMatch(resolveSiteMeta(EMPTY, "PekanGame").description, /3 minutes/i);
});

test("the admin's default title and description win, tokens rendered", () => {
  const meta = resolveSiteMeta(
    { ...EMPTY, default_meta_title: "{store_name} Top Up MLBB Murah", default_meta_description: "Top up ML di {store_name}." },
    "PekanGame",
  );
  assert.equal(meta.title, "PekanGame Top Up MLBB Murah");
  assert.equal(meta.description, "Top up ML di PekanGame.");
});

test("a blank admin field counts as empty, per field", () => {
  const meta = resolveSiteMeta({ ...EMPTY, default_meta_title: "  ", default_meta_description: "Own description" }, "PekanGame");
  assert.equal(meta.title, pageMeta("home", "PekanGame").title);
  assert.equal(meta.description, "Own description");
});

// The game chain ADR-120 decision 5 set, which ADR-140 decision 3 extends in PR 2.
test("a game's meta: its own override, then the template, then the default", () => {
  const game = { name: "Valorant", seoTitle: null, seoDescription: null };
  const settings = { ...EMPTY, meta_title_template: "Top Up {game_name} | {store_name}", default_meta_description: "Default" };
  assert.equal(resolveGameMeta(game, settings, "PekanGame").title, "Top Up Valorant | PekanGame");
  assert.equal(resolveGameMeta(game, settings, "PekanGame").description, "Default");
  assert.equal(resolveGameMeta({ ...game, seoDescription: "Own" }, settings, "PekanGame").description, "Own");
});
