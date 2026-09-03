import { revalidateTag } from "next/cache";
import { CATALOG_TAG } from "@/lib/cache";

/**
 * ADR-071 PR2 — the backend calls this after any catalog / SEO /
 * branding / hero / payment mutation (Laravel `NextRevalidation::purge()`,
 * dispatched next to every `forgetCache()`), purging the `catalog` tag
 * so a price or content change shows on the storefront within seconds
 * rather than waiting out the 60s Data-Cache TTL. The TTL stays as the
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

  // Next 16: the second arg is required. `"max"` = stale-while-
  // revalidate — the next visitor to a page with this tag is served the
  // old value once while the fresh one fetches in the background, then
  // everyone sees fresh. `updateTag` (blocking revalidate) is Server-
  // Action-only and can't be used from a Route Handler like this.
  revalidateTag(CATALOG_TAG, "max");
  return Response.json({ revalidated: true, tag: CATALOG_TAG, now: Date.now() });
}
