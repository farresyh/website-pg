import { test, expect } from "@playwright/test";
import { STOREFRONT_URL, E2E_GAME_SLUG } from "./constants";

// ADR-120: what a crawler gets from the raw HTML (no JS) — tokens
// rendered, a canonical on the brand's own origin, Product JSON-LD.
// The seeded game's per-game title carries `{game_name}`/`{store_name}`
// (E2ESeeder), the exact shape that once shipped raw to Google.
test("game page serves crawler-ready meta", async ({ request }) => {
  const response = await request.get(`${STOREFRONT_URL}/order/${E2E_GAME_SLUG}`);
  expect(response.status()).toBe(200);
  const html = await response.text();

  const title = html.match(/<title>([^<]*)<\/title>/)?.[1] ?? "";
  expect(title).toContain("E2E Test Game Top Up |");
  expect(title).not.toMatch(/[{}]/);

  expect(html).toContain(`<link rel="canonical" href="${STOREFRONT_URL}/order/${E2E_GAME_SLUG}"/>`);
  expect(html).toContain('"@type":"Product"');
  // ADR-120 decision 9: the guest Standard price range, in MYR.
  expect(html).toMatch(/"offers":\{"@type":"AggregateOffer","priceCurrency":"MYR","lowPrice":"\d+\.\d{2}"/);
});
