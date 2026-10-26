import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

/**
 * ADR-128 (as the admin's PRD §16 item 100 test): Tailwind v4 silently drops
 * a colour utility whose `--color-*` token doesn't exist. This fails when a
 * class names a shade of a project colour that neither the generated ADR-104
 * tokens, globals.css nor the tailwindcss-primeui plugin defines.
 */
const TAILWIND_PALETTES = new Set([
  "slate", "gray", "zinc", "neutral", "stone", "red", "orange", "amber", "yellow", "lime", "green",
  "emerald", "teal", "cyan", "sky", "blue", "indigo", "violet", "purple", "fuchsia", "pink", "rose",
]);

const SRC = join(import.meta.dirname, "..");
const css = [
  join(SRC, "app/globals.css"),
  join(SRC, "app/tokens.generated.css"),
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

test("every project colour shade used in a class has a token", () => {
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
    "Define the token in admin/src/app/tokens.css (then sync) or use a shade that exists.",
  );
});

/**
 * ADR-128 PR 4 (as the admin's PRD §16 item 101 check): the TailAdmin greys
 * and brand blue are gone from the portal's screens. Text and surfaces use
 * the semantic tokens (`ink`, `ink-muted`, `ink-subtle`, `surface`,
 * `danger-ink` …), which carry their own dark values, so a `dark:` colour
 * override is never needed either.
 */
test("no TailAdmin colour classes or dark: colour overrides on portal screens", () => {
  const LEGACY = /(?<![\w-])(?:[a-z-]+:)*(?:text|bg|border|ring|outline|divide|placeholder)-(?:gray|brand|error|blue-light)-\d{2,3}(?:\/[\w.[\]]+)?(?![\w-])|(?<![\w-])dark:(?:text|bg|border|ring|outline|divide|placeholder)-[\w/[\].-]+/g;
  const hits = sourceFiles(SRC).flatMap((file) =>
    [...readFileSync(file, "utf8").matchAll(LEGACY)].map((m) => `${m[0]} (${file.slice(SRC.length + 1)})`),
  );
  assert.deepEqual(hits, [], "Use the semantic tokens (ink, ink-muted, ink-subtle, surface, subtle, border, danger-ink …).");
});
