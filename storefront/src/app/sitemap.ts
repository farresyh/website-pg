import { getJokiCatalog } from "@/lib/joki";
import type { MetadataRoute } from "next";
import { listGames } from "@/lib/catalog";
import { getResellerPriceListEnabled } from "@/lib/reseller-price-list";
import { getBranding } from "@/lib/branding";
import { listPlayerCheckFamilies } from "@/lib/player-check";
import { buildSitemapEntries } from "@/lib/sitemap-entries";

/**
 * Forces this to fetch at request time rather than build time — a
 * game added/removed between deploys should show up without a
 * rebuild, and this also avoids requiring the backend to be reachable
 * during `next build` (which a static/cached sitemap would need).
 */
export const dynamic = "force-dynamic";

/**
 * ADR-029 addendum decision 16 — fully automatic, no admin-configurable
 * priority/changefreq (deliberately rejected as a vanity control with
 * no real SEO effect). Static routes plus one entry per game's
 * /order/[slug] page. ADR-120 decision 1: every URL is on the serving
 * brand's own canonical origin, never the platform's.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [branding, games, priceListEnabled, playerCheck, jokiCatalog] = await Promise.all([
    getBranding(),
    listGames(),
    getResellerPriceListEnabled(),
    listPlayerCheckFamilies(),
    getJokiCatalog(),
  ]);

  // The rows and their rules (honest lastmod, noindex games out) live in
  // lib/sitemap-entries.ts, where they are tested (ADR-140 decision 4).
  return buildSitemapEntries({
    origin: branding.canonicalOrigin,
    games,
    priceList: priceListEnabled,
    // ADR-123 decision 10: the Game ID Checker is listed on the primary brand only.
    checkIdSlugs: playerCheck.indexable ? playerCheck.families.map((family) => family.slug) : [],
    // ADR-126 decision 2: only where /joki renders on this brand.
    joki: jokiCatalog !== null,
  });
}
