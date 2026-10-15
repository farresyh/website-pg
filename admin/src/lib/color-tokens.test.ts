import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

/**
 * PRD §16 item 100: Tailwind v4 silently drops a colour utility whose
 * `--color-*` token doesn't exist. `dark:text-error-400` (~190 uses) did
 * exactly that for months. This fails when a class names a shade of a
 * project colour (error, success, warning, brand, …) that neither
 * globals.css nor the tailwindcss-primeui plugin defines. Tailwind's own
 * palettes are always defined (no `--color-*: initial` reset in globals.css).
 */
const TAILWIND_PALETTES = new Set([
  "slate", "gray", "zinc", "neutral", "stone", "red", "orange", "amber", "yellow", "lime", "green",
  "emerald", "teal", "cyan", "sky", "blue", "indigo", "violet", "purple", "fuchsia", "pink", "rose",
]);

const SRC = join(import.meta.dirname, "..");
// globals.css plus the tailwindcss-primeui plugin it imports (primary-*, surface-*).
const css = [
  join(SRC, "app/globals.css"),
  join(SRC, "../node_modules/tailwindcss-primeui/v4/theme/colors.css"),
].map((path) => readFileSync(path, "utf8")).join("\n");
const defined = new Set([...css.matchAll(/--color-([a-z0-9-]+):/g)].map((m) => m[1]));

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return sourceFiles(path);
    return /\.tsx?$/.test(entry.name) && !entry.name.endsWith(".test.ts") ? [path] : [];
  });
}

const UTILITY = /(?<![\w-])(?:[a-z-]+:)*(?:text|bg|border|ring|fill|stroke|from|via|to|divide|outline|placeholder|decoration|accent|caret|shadow)-([a-z]+(?:-[a-z]+)*)-(\d{2,3})(?:\/\d+)?(?![\w-])/g;

test("every project colour shade used in a class has a token in globals.css", () => {
  const missing = new Map<string, string>();

  for (const file of sourceFiles(SRC)) {
    for (const [, family, shade] of readFileSync(file, "utf8").matchAll(UTILITY)) {
      if (TAILWIND_PALETTES.has(family)) continue;
      const token = `${family}-${shade}`;
      if (!defined.has(token)) missing.set(token, file.slice(SRC.length + 1));
    }
  }

  assert.deepEqual(
    [...missing].map(([token, file]) => `${token} (e.g. ${file})`),
    [],
    "Define the token in globals.css or use a shade that exists.",
  );
});

/**
 * PRD §16 item 101: light `text-gray-400` measured 2.3:1 and `text-error-500`
 * stayed #b42318 (2.5:1) in dark mode. Use `text-ink-subtle` / `text-danger-ink`,
 * which carry their own `.dark` values. Variant-prefixed uses (`dark:`, `hover:`) are fine.
 */
test("no low-contrast text-gray-400 / text-error-500 as a base text colour", () => {
  const BANNED = /(?<![:\w-])text-(?:gray-400|error-500)(?![\w/-])/g;
  const hits = sourceFiles(SRC).flatMap((file) =>
    [...readFileSync(file, "utf8").matchAll(BANNED)].map((m) => `${m[0]} (${file.slice(SRC.length + 1)})`),
  );
  assert.deepEqual(hits, [], "Use text-ink-subtle or text-danger-ink.");
});
