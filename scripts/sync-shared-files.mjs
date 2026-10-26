#!/usr/bin/env node
// Writes the partner portal's generated copies of files another app owns:
//  - the affiliate storefront theme presets (storefront → reseller), which
//    must stay byte-for-byte identical (see the header inside that file,
//    ADR-081/090);
//  - the ADR-104 design tokens (admin → reseller), ADR-128 decision 7.
// A copy lives inside reseller/ so Vercel's per-app ignoreCommand rebuilds
// the portal whenever its copy changes. CI's `theme-preset-drift` job runs
// this and fails on any diff — run it locally after editing a source.
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const repoRoot = dirname(dirname(fileURLToPath(import.meta.url)));

const copies = [
  { source: "storefront/src/lib/theme-presets.ts", target: "reseller/src/lib/theme-presets.ts", banner: "" },
  {
    source: "admin/src/app/tokens.css",
    target: "reseller/src/app/tokens.generated.css",
    banner: "/* GENERATED from admin/src/app/tokens.css by scripts/sync-shared-files.mjs. Do not edit. */\n",
  },
];

for (const { source, target, banner } of copies) {
  writeFileSync(join(repoRoot, target), banner + readFileSync(join(repoRoot, source), "utf8"));
  console.log(`Synced ${target}\n  from ${source}`);
}
