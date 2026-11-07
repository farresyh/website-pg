import { test, expect } from "@playwright/test";
import { STOREFRONT_URL } from "./constants";

// ADR-078 2026-11-08 addendum: `proxy.ts` runs before every page, and only
// before pages. Each check here goes through the real file.
const UNKNOWN_HOST = { "x-forwarded-host": "unknown-store.example" };

test("a saved redirect fires", async ({ request }) => {
  const response = await request.get(`${STOREFRONT_URL}/e2e-old-path`, { maxRedirects: 0 });

  expect(response.status()).toBe(301);
  expect(response.headers().location).toMatch(/\/track-order$/);
});

test("an unknown host gets the hard unavailable page", async ({ request }) => {
  const response = await request.get(`${STOREFRONT_URL}/`, { headers: UNKNOWN_HOST });

  expect(response.status()).toBe(503);
});

test("a static image skips the proxy", async ({ request }) => {
  // On an unknown host the proxy answers 503 for anything it sees, so a 200
  // here is proof it never ran.
  const response = await request.get(`${STOREFRONT_URL}/images/banks/mb2u0227.webp`, { headers: UNKNOWN_HOST });

  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toBe("image/webp");
});
