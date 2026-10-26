#!/usr/bin/env node
// Writes the partner portal's generated copies of files another app owns:
//  - the affiliate storefront theme presets (storefront → reseller), which
//    must stay byte-for-byte identical (see the header inside that file,
//    ADR-081/090);
//  - the ADR-104 design tokens and the font files they declare
//    (admin → reseller), ADR-128 decision 7;
//  - the shared component kit: the admin's `components/ui` PrimeReact
//    wrappers and its `lib/utils` `cn` helper (admin → reseller), ADR-128
//    2026-10-26 addendum. `RichTextEditor` (the one hand-rolled primitive)
//    is deliberately NOT shared;
//  - the official payment marks and the CHIP badge (storefront → reseller),
//    so the portal's top-up shows the same artwork as checkout.
// A copy lives inside reseller/ so Vercel's per-app ignoreCommand rebuilds
// the portal whenever its copy changes. CI's `theme-preset-drift` job runs
// this and fails on any diff — run it locally after editing a source.
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const repoRoot = dirname(dirname(fileURLToPath(import.meta.url)));

// The shared kit: the admin's PrimeReact wrappers plus its `cn` helper.
// Verbatim copy (no banner) — they carry `'use client'` as the first line
// and `@/` imports that resolve identically in the portal.
const KIT = [
  "button", "datatable", "dialog", "input", "label", "option-select",
  "popover", "select", "switch", "tabs", "tag", "textarea",
].map((name) => ({
  source: `admin/src/components/ui/${name}.tsx`,
  target: `reseller/src/components/ui/${name}.tsx`,
  banner: "",
}));

const copies = [
  { source: "storefront/src/lib/theme-presets.ts", target: "reseller/src/lib/theme-presets.ts", banner: "" },
  {
    source: "admin/src/app/tokens.css",
    target: "reseller/src/app/tokens.generated.css",
    banner: "/* GENERATED from admin/src/app/tokens.css by scripts/sync-shared-files.mjs. Do not edit. */\n",
  },
  { source: "admin/src/lib/utils.ts", target: "reseller/src/lib/utils.ts", banner: "" },
  ...KIT,
  ...["jetbrains-mono-400.woff2", "jetbrains-mono-500.woff2"].map((font) => ({
    source: `admin/src/app/fonts/${font}`,
    target: `reseller/src/app/fonts/${font}`,
    banner: null,
  })),
  ...["payments/fpx.png", "payments/duitnow-qr.svg", "payments/cards.svg", "payments/ewallets.svg", "chip/powered-by-chip-long.svg"].map((image) => ({
    source: `storefront/public/images/${image}`,
    target: `reseller/public/images/${image}`,
    banner: null,
  })),
];

for (const { source, target, banner } of copies) {
  mkdirSync(dirname(join(repoRoot, target)), { recursive: true });
  const content = readFileSync(join(repoRoot, source));
  // A banner goes only on text; binary files (fonts) are copied byte for byte.
  writeFileSync(join(repoRoot, target), banner === null ? content : banner + content.toString("utf8"));
  console.log(`Synced ${target}\n  from ${source}`);
}
