// ADR-071 2026-10-17 addendum: every storefront route renders per request
// (ADR-060's per-Host fetch reads `headers()`), so a route with no
// `loading.tsx` freezes the old page until the new one finishes rendering.
// Fails when a `page.tsx` has no `loading.tsx` beside it, unless listed below.
import { existsSync, readdirSync } from "node:fs";
import { join, relative } from "node:path";

const APP = new URL("../src/app/", import.meta.url).pathname;

// route dir (relative to src/app) → why it needs no skeleton
const EXEMPT = {
  "store-unavailable": "sync component with no data fetch, never suspends",
};

const missing = [];
(function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) walk(join(dir, entry.name));
  }
  const route = relative(APP, dir);
  if (existsSync(join(dir, "page.tsx")) && !existsSync(join(dir, "loading.tsx")) && !(route in EXEMPT)) {
    missing.push(`src/app/${route || "."}`);
  }
})(APP);

if (missing.length > 0) {
  console.error("Routes with no loading.tsx (navigation will freeze with no feedback):");
  for (const route of missing) console.error(`  ${route}`);
  console.error("Add a matched skeleton (see src/components/skeletons/) or an EXEMPT entry with the reason.");
  process.exit(1);
}
console.log("Every storefront route has a loading.tsx.");
