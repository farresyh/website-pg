import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { THEME_PRESETS } from "./theme-presets.ts";

// ADR-113 (2026-11-10): a dark preset's `ink` is a light stroke, so a
// backdrop drawn in `ink` lightens the page and the popup blends into it.
// Popups dim the page with `scrim`, and every dark preset sets its own.

test("every dark preset sets its own scrim", () => {
  for (const preset of Object.values(THEME_PRESETS)) {
    if (!preset.tokensDark) continue;
    assert.ok(preset.tokensDark["--color-scrim"], `${preset.id}: tokensDark has no --color-scrim`);
  }
});

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return sourceFiles(path);
    return path.endsWith(".tsx") ? [path] : [];
  });
}

test("no full-screen overlay draws its backdrop in ink", () => {
  const src = join(import.meta.dirname, "..");
  const offenders = sourceFiles(src).flatMap((file) =>
    (readFileSync(file, "utf8").match(/"[^"\n]*fixed inset-0[^"\n]*"/g) ?? [])
      .filter((className) => /\bbg-ink\//.test(className))
      .map((className) => `${file}: ${className}`),
  );
  assert.deepEqual(offenders, [], "use bg-scrim/… for a popup backdrop");
});
