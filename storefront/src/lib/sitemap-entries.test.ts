import { test } from "node:test";
import assert from "node:assert/strict";
import { buildSitemapEntries } from "./sitemap-entries.ts";
import { indexableGames } from "./indexable-games.ts";

// ADR-140 decision 4: an honest sitemap. A page's `lastmod` is only sent when
// it is a real date; "now" on every request teaches Google to ignore it.

const ORIGIN = "https://pekangame.com";
const base = { origin: ORIGIN, games: [], priceList: false, checkIdSlugs: [], joki: false };

test("static pages carry no lastmod", () => {
  const entries = buildSitemapEntries(base);
  assert.deepEqual(
    entries.map((e) => e.url),
    [ORIGIN, `${ORIGIN}/terms`, `${ORIGIN}/privacy`, `${ORIGIN}/about-us`, `${ORIGIN}/refund-policy`, `${ORIGIN}/track-order`],
  );
  assert.ok(entries.every((e) => e.lastModified === undefined));
});

test("a game's lastmod is its real date, and absent when it has none", () => {
  const entries = buildSitemapEntries({
    ...base,
    games: [
      { slug: "valorant", addedAt: "2026-09-29T14:22:10.000Z", noIndex: false },
      { slug: "undated", addedAt: "", noIndex: false },
    ],
  });
  const valorant = entries.find((e) => e.url.endsWith("/order/valorant"));
  const undated = entries.find((e) => e.url.endsWith("/order/undated"));
  assert.equal(valorant?.lastModified?.toISOString(), "2026-09-29T14:22:10.000Z");
  assert.equal(undated?.lastModified, undefined);
});

test("a published content date is the game's lastmod, ahead of its creation date", () => {
  const entries = buildSitemapEntries({
    ...base,
    games: [{ slug: "valorant", addedAt: "2026-09-29T14:22:10.000Z", contentPublishedAt: "2026-11-20T03:00:00.000Z", noIndex: false }],
  });
  assert.equal(entries.find((e) => e.url.endsWith("/order/valorant"))?.lastModified?.toISOString(), "2026-11-20T03:00:00.000Z");
});

test("a noindex game is left out", () => {
  const urls = buildSitemapEntries({
    ...base,
    games: [
      { slug: "listed", addedAt: "", noIndex: false },
      { slug: "hidden", addedAt: "", noIndex: true },
    ],
  }).map((e) => e.url);
  assert.ok(urls.includes(`${ORIGIN}/order/listed`));
  assert.ok(!urls.includes(`${ORIGIN}/order/hidden`));
});

test("optional pages appear only where they render", () => {
  const off = buildSitemapEntries(base).map((e) => e.url);
  for (const path of ["/price-list", "/check-id", "/joki"]) assert.ok(!off.includes(`${ORIGIN}${path}`), path);

  const on = buildSitemapEntries({ ...base, priceList: true, checkIdSlugs: ["mobile-legends"], joki: true }).map((e) => e.url);
  for (const path of ["/price-list", "/check-id", "/check-id/mobile-legends", "/joki"]) assert.ok(on.includes(`${ORIGIN}${path}`), path);
});

test("the shared filter drops only noindex games and keeps order", () => {
  const games = [
    { slug: "a", noIndex: false },
    { slug: "b", noIndex: true },
    { slug: "c", noIndex: false },
  ];
  assert.deepEqual(indexableGames(games).map((g) => g.slug), ["a", "c"]);
});
