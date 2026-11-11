import { test } from "node:test";
import assert from "node:assert/strict";
import { resolveGameMeta, resolveSiteMeta, truncateAtWord, type SeoTextSettings } from "./seo-meta.ts";
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

// ADR-140 decision 3: a game's published summary is its meta description when
// it has no override of its own, ahead of the shared template.
test("a game's meta description: override, then its summary, then the template", () => {
  const settings = { ...EMPTY, meta_description_template: "Template for {game_name}" };
  const game = { name: "Valorant", seoTitle: null, seoDescription: null, contentSummary: "Top up Valorant Points pantas." };
  assert.equal(resolveGameMeta(game, settings, "PekanGame").description, "Top up Valorant Points pantas.");
  assert.equal(resolveGameMeta({ ...game, seoDescription: "Own" }, settings, "PekanGame").description, "Own");
  assert.equal(resolveGameMeta({ ...game, contentSummary: null }, settings, "PekanGame").description, "Template for Valorant");
  assert.equal(resolveGameMeta({ ...game, contentSummary: "   " }, settings, "PekanGame").description, "Template for Valorant");
});

test("a long summary is cut at a word, with an ellipsis, inside 155 characters", () => {
  const long = "Top up diamond Mobile Legends untuk akaun Malaysia dengan mudah. ".repeat(6);
  const meta = resolveGameMeta({ name: "ML", seoTitle: null, seoDescription: null, contentSummary: long }, EMPTY, "PekanGame").description;
  assert.ok(meta.length <= 155, String(meta.length));
  assert.ok(meta.endsWith("…"));
  assert.doesNotMatch(meta, /\s…$/);
  assert.ok(long.startsWith(meta.slice(0, -1)), "a prefix of the summary, never a rewrite");
});

test("truncateAtWord keeps short text, collapses whitespace and survives one huge word", () => {
  assert.equal(truncateAtWord("Pendek sahaja.", 155), "Pendek sahaja.");
  assert.equal(truncateAtWord("Satu\n\ndua   tiga", 155), "Satu dua tiga");
  const out = truncateAtWord("x".repeat(400), 155);
  assert.equal(out.length, 155);
  assert.ok(out.endsWith("…"));
});
