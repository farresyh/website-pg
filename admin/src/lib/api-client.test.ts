import { afterEach, test } from "node:test";
import assert from "node:assert/strict";
import { apiFetch, forgetCsrfToken } from "./api-client.ts";

// ADR-121 2026-10-19 addendum: the API is another origin, so any
// non-safelisted request header makes the browser send an OPTIONS preflight
// before the real request. A read must not carry one.
const CORS_SAFELISTED = new Set(["accept", "accept-language", "content-language"]);

type Call = { url: string; method: string; headers: Headers };

const realFetch = globalThis.fetch;
afterEach(() => {
  globalThis.fetch = realFetch;
  forgetCsrfToken();
});

function recordFetch(): Call[] {
  const calls: Call[] = [];
  globalThis.fetch = (async (input: string | URL | Request, init: RequestInit = {}) => {
    calls.push({ url: String(input), method: (init.method ?? "GET").toUpperCase(), headers: new Headers(init.headers) });
    const body = String(input).endsWith("/api/csrf-token") ? { token: "csrf-123" } : { ok: true };
    return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
  }) as typeof fetch;
  return calls;
}

test("a GET sends only CORS-safelisted headers, so no preflight", async () => {
  const calls = recordFetch();

  await apiFetch("/api/orders?page=1");

  assert.equal(calls.length, 1);
  assert.equal(calls[0].method, "GET");
  assert.equal(calls[0].headers.get("Accept"), "application/json");
  assert.deepEqual([...calls[0].headers.keys()].filter((name) => !CORS_SAFELISTED.has(name)), []);
});

test("a request with a body sends its JSON Content-Type and the CSRF header", async () => {
  const calls = recordFetch();

  await apiFetch("/api/orders/1/retry", { method: "POST", body: { reason: "x" } });

  const post = calls.find((call) => call.method === "POST");
  assert.ok(post);
  assert.equal(post.headers.get("Content-Type"), "application/json");
  assert.equal(post.headers.get("X-CSRF-TOKEN"), "csrf-123");
});
