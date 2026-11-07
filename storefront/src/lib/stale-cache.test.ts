import { test } from "node:test";
import assert from "node:assert/strict";
import { createStaleCache } from "./stale-cache.ts";

function setup() {
  let time = 0;
  const cache = createStaleCache<string>({ freshMs: 100, staleLimitMs: 1_000, now: () => time });
  const background: Promise<unknown>[] = [];
  return {
    cache,
    advance: (ms: number) => { time += ms; },
    run: (work: Promise<unknown>) => { background.push(work); },
    settle: () => Promise.all(background),
    background,
  };
}

test("the first read waits for the load, because there is nothing to serve", async () => {
  const { cache, run, background } = setup();

  assert.equal(await cache.get("k", async () => "loaded", run), "loaded");
  assert.equal(background.length, 0);
});

test("a fresh value costs no load", async () => {
  const { cache, run, advance } = setup();
  await cache.get("k", async () => "first", run);
  advance(99);

  let loads = 0;
  const value = await cache.get("k", async () => { loads++; return "second"; }, run);

  assert.equal(value, "first");
  assert.equal(loads, 0);
});

test("an expired value is served at once and refreshed behind it", async () => {
  const { cache, run, advance, settle } = setup();
  await cache.get("k", async () => "old", run);
  advance(100);

  let finish: (value: string) => void = () => {};
  const slowLoad = () => new Promise<string>((resolve) => { finish = resolve; });

  // Resolves while the load is still open: the caller never waits for it.
  assert.equal(await cache.get("k", slowLoad, run), "old");

  finish("new");
  await settle();
  assert.equal(await cache.get("k", async () => "unused", run), "new");
});

test("readers during a refresh share one load", async () => {
  const { cache, run, advance, settle } = setup();
  await cache.get("k", async () => "old", run);
  advance(100);

  let loads = 0;
  const load = async () => { loads++; return "new"; };
  await Promise.all(Array.from({ length: 20 }, () => cache.get("k", load, run)));
  await settle();

  assert.equal(loads, 1);
});

test("readers of an empty cache share one load too", async () => {
  const { cache, run } = setup();

  let loads = 0;
  const load = async () => { loads++; return "loaded"; };
  const values = await Promise.all(Array.from({ length: 20 }, () => cache.get("k", load, run)));

  assert.equal(loads, 1);
  assert.deepEqual(new Set(values), new Set(["loaded"]));
});

test("a value past the stale limit is not served: the reader waits for a new one", async () => {
  const { cache, run, advance } = setup();
  await cache.get("k", async () => "ancient", run);
  advance(1_000);

  assert.equal(await cache.get("k", async () => "new", run), "new");
});

test("a load receives the value it replaces, so a failed read can keep it", async () => {
  const { cache, run, advance, settle } = setup();
  await cache.get("k", async () => "kept", run);
  advance(100);

  await cache.get("k", async (previous) => previous ?? "lost", run);
  await settle();

  assert.equal(await cache.get("k", async () => "unused", run), "kept");
});

test("keys don't share values or loads", async () => {
  const { cache, run } = setup();

  assert.equal(await cache.get("a", async () => "for a", run), "for a");
  assert.equal(await cache.get("b", async () => "for b", run), "for b");
});
