import { revalidateTag } from "next/cache";
import { CATALOG_TAG } from "@/lib/cache";

/**
 * ADR-071 PR2 — the backend calls this after any catalog / SEO /
 * branding / hero / payment mutation (Laravel `NextRevalidation::purge()`,
 * dispatched next to every `forgetCache()`), purging the `catalog` tag
 * so a price or content change shows on the storefront within seconds
 * rather than waiting out the 30s Data-Cache TTL. The TTL stays as the
 * backstop if this call is ever missed.
 *
 * Auth is a shared secret header, mirroring how the backend's own
 * webhook endpoints authenticate (a signature / secret, not a session).
 * `REVALIDATE_SECRET` must be set on both sides; if it is unset here the
 * endpoint refuses every request (fail closed).
 */
export const dynamic = "force-dynamic";

export async function POST(request: Request): Promise<Response> {
  const expected = process.env.REVALIDATE_SECRET;
  const provided = request.headers.get("x-revalidate-secret");

  if (!expected || provided !== expected) {
    return Response.json({ error: "unauthorized" }, { status: 401 });
  }

  // ADR-077 PR-3 decision 9: no `"max"` (stale-while-revalidate) — that
  // was the "must refresh 2–3 times" root (first post-purge request
  // served stale, only *triggers* a background regen). `{ expire: 0 }`
  // expires the tag immediately, so the next request is a blocking
  // cache-miss and comes back fresh. Per the Next 16 revalidateTag docs
  // this is the sanctioned pattern for an external webhook that needs
  // immediate expiration; the bare `revalidateTag(tag)` form is
  // deprecated (works only with TS errors suppressed) and `updateTag`'s
  // blocking mode is Server-Action-only, unreachable from a Route Handler.
  revalidateTag(CATALOG_TAG, { expire: 0 });
  return Response.json({ revalidated: true, tag: CATALOG_TAG, now: Date.now() });
}
