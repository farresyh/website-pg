import { unstable_rethrow } from "next/navigation";

/**
 * ADR-071 PR1 — the storefront's read-mostly catalog/SEO/branding data
 * is cached in Next's Data Cache under one coarse `catalog` tag.
 *
 * Freshness comes from the Laravel → `POST /api/revalidate` webhook
 * (PR2) that purges this tag on every catalog/SEO/branding/hero/payment
 * mutation — mirroring the backend's own `forgetCache()` discipline one
 * layer up. ADR-077 PR-3 decision 9 switched that purge from SWR
 * (`"max"`) to immediate expiry (`{ expire: 0 }`) so the next request
 * after a purge is a blocking cache-miss and comes back fresh (was
 * "must refresh 2–3 times"); this 30s TTL (was 60s) is now purely a
 * failure backstop for a webhook that never arrived.
 *
 * ADR-060 PR-3: the `X-Storefront-Host` header + `__brand` query param
 * (lib/api-client.ts) partition every cached read per brand; the coarse
 * `catalog` tag still purges all brands at once on any mutation, which
 * is acceptable — a branding edit is rare and cross-brand invalidation
 * is cheap. An unrecognised `Host` is caught earlier by `proxy.ts`
 * (ADR-060 PR-5), which rewrites to `/store-unavailable` before any of
 * these reads run, so a `safeRead` fallback here is only ever a real
 * backend blip on a known brand.
 *
 * Display staleness is never a mischarge: the payable total is always
 * recomputed server-side at checkout from stored Package/Game data
 * (ORD-9), and the fee/voucher/total preview endpoints are per-request
 * and uncached.
 */
export const CATALOG_TAG = "catalog";

export const catalogCache: { revalidate: number; tags: string[] } = {
  revalidate: 30,
  tags: [CATALOG_TAG],
};

/**
 * Run a cached catalog/SEO/branding read, falling back to `fallback` on
 * any failure (network, backend down, schema drift). Two reasons this
 * has to be graceful (ADR-071 PR1):
 *
 *  1. `next build` prerenders `/` and the auto-generated `/_not-found`
 *     through the root layout — with no reachable backend in CI or a
 *     container build, an unguarded fetch fails the build. This is why
 *     the root layout carried `force-dynamic` before; removing it (so
 *     `loading.tsx` and RSC prefetch work) needs this instead.
 *  2. A backend blip in production now degrades the storefront (empty
 *     catalog, default branding) rather than throwing a 500.
 *
 * The failure is logged so a persistent outage is visible in the
 * platform logs, not silent.
 */
export async function safeRead<T>(label: string, read: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await read();
  } catch (err) {
    // Let Next's control-flow errors through — the `headers()`
    // dynamic-rendering bail-out (ADR-060 PR-3: a multi-tenant shell is
    // per-request), plus `notFound()` / `redirect()`. Only a real read
    // failure (backend down, schema drift) falls through to the fallback.
    unstable_rethrow(err);
    console.error(`[catalog] ${label} failed — serving fallback:`, err instanceof Error ? err.message : err);
    return fallback;
  }
}
