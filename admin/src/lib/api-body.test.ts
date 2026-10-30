import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

/**
 * `apiFetch` JSON-encodes its `body` itself. A caller that passes an
 * already-encoded string sends the server a JSON *string*, so Laravel sees
 * none of the fields: the domain payments approval failed with "The approved
 * field is required" for exactly this (2026-10-30, ADR-131 PR C). Any file
 * that uses `apiFetch` must pass `body` as an object.
 */
function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return sources(path);
    return /\.(ts|tsx)$/.test(entry.name) && !entry.name.endsWith(".test.ts") ? [path] : [];
  });
}

test("no file that uses apiFetch passes it a JSON.stringify'd body", () => {
  const offenders = sources("src")
    .filter((path) => {
      const text = readFileSync(path, "utf8");
      return /\bapiFetch\b/.test(text) && /body:\s*JSON\.stringify\(/.test(text);
    });

  assert.deepEqual(offenders, [], `pass the object, apiFetch encodes it: ${offenders.join(", ")}`);
});
