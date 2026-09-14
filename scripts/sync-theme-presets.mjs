#!/usr/bin/env node
// Copies the canonical affiliate storefront theme-preset file
// (storefront/src/lib/theme-presets.ts) over its generated copy at
// reseller/src/lib/theme-presets.ts. See the header comment inside that
// file for why these two must stay byte-for-byte identical (ADR-081/090).
// CI's `theme-preset-drift` job (.github/workflows/ci.yml) runs this and
// fails the build on any diff — run it locally after editing presets.
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const repoRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const source = join(repoRoot, "storefront/src/lib/theme-presets.ts");
const target = join(repoRoot, "reseller/src/lib/theme-presets.ts");

writeFileSync(target, readFileSync(source, "utf8"));
console.log(`Synced ${target}\n  from ${source}`);
