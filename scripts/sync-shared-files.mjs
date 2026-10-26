#!/usr/bin/env node
// Writes the partner portal's generated copies of files another app owns:
//  - the affiliate storefront theme presets (storefront → reseller), which
//    must stay byte-for-byte identical (see the header inside that file,
//    ADR-081/090);
//  - the ADR-104 design tokens and the font files they declare
//    (admin → reseller), ADR-128 decision 7.
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
  ...["jetbrains-mono-400.woff2", "jetbrains-mono-500.woff2"].map((font) => ({
    source: `admin/src/app/fonts/${font}`,
    target: `reseller/src/app/fonts/${font}`,
    banner: null,
  })),
];

for (const { source, target, banner } of copies) {
  const content = readFileSync(join(repoRoot, source));
  // A banner goes only on text; binary files (fonts) are copied byte for byte.
  writeFileSync(join(repoRoot, target), banner === null ? content : banner + content.toString("utf8"));
  console.log(`Synced ${target}\n  from ${source}`);
}
