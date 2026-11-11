import { test } from "node:test";
import assert from "node:assert/strict";
import { PAGE_META_KEYS, pageMeta } from "./page-meta.ts";

// ADR-140 decisions 1–2: one registry gives every static page its own title
// and description, in the words Malaysians type, with no claim the data
// can't back. The bug this replaces: four pages shared one hardcoded
// description ("... Delivered in 3 minutes.") because they had none.

const rendered = PAGE_META_KEYS.map((key) => ({ key, ...pageMeta(key, "PekanGame") }));

test("no two pages share a title or a description", () => {
  for (const field of ["title", "description"] as const) {
    const values = rendered.map((page) => page[field]);
    assert.equal(new Set(values).size, values.length, `a ${field} is used by two pages`);
  }
});

test("a title and a description stay inside what Google shows", () => {
  for (const { key, title, description } of rendered) {
    assert.ok(title.length <= 60, `${key} title is ${title.length} characters`);
    assert.ok(description.length >= 70 && description.length <= 160, `${key} description is ${description.length} characters`);
  }
});

test("the store name is in every title and no token is left over", () => {
  for (const { key, title, description } of rendered) {
    assert.ok(title.includes("PekanGame"), `${key} title lacks the store name`);
    assert.doesNotMatch(`${title} ${description}`, /[{}]/, `${key} has an unreplaced token`);
  }
});

test("an affiliate's pages carry its own name, never the platform's", () => {
  for (const key of PAGE_META_KEYS) {
    const { title, description } = pageMeta(key, "Acme Resell");
    assert.ok(title.includes("Acme Resell"), `${key} title`);
    assert.doesNotMatch(`${title} ${description}`, /PekanGame/, `${key} leaks the platform name`);
  }
});

test("no page states a delivery time", () => {
  for (const { key, title, description } of rendered) {
    assert.doesNotMatch(`${title} ${description}`, /\b\d+\s*(minit|minute|min|saat|second|jam|hour)/i, `${key} claims a time`);
  }
});

test("the store name is substituted as plain text", () => {
  const { title } = pageMeta("about-us", "A {store_name} B");
  assert.ok(title.includes("A {store_name} B"));
});
