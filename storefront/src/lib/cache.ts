/**
 * ADR-071 PR1 — the storefront's read-mostly catalog/SEO/branding data
 * is cached in Next's Data Cache under one coarse `catalog` tag.
 *
 * The 60s TTL is an interim freshness floor. PR2 wires a Laravel →
 * `POST /api/revalidate` webhook that purges this tag on every catalog/
 * SEO/branding/hero/payment mutation — mirroring the backend's own
 * `forgetCache()` discipline one layer up — after which staleness is
 * near-zero and the TTL is just a backstop.
 *
 * Display staleness is never a mischarge: the payable total is always
 * recomputed server-side at checkout from stored Package/Game data
 * (ORD-9), and the fee/voucher/total preview endpoints are per-request
 * and uncached.
 */
export const CATALOG_TAG = "catalog";

export const catalogCache: { revalidate: number; tags: string[] } = {
  revalidate: 60,
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
    console.error(`[catalog] ${label} failed — serving fallback:`, err instanceof Error ? err.message : err);
    return fallback;
  }
}
