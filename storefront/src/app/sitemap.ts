import { getJokiCatalog } from "@/lib/joki";
import type { MetadataRoute } from "next";
import { listGames } from "@/lib/catalog";
import { getResellerPriceListEnabled } from "@/lib/reseller-price-list";
import { getBranding } from "@/lib/branding";
import { listPlayerCheckFamilies } from "@/lib/player-check";

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
  const origin = branding.canonicalOrigin;

  const staticRoutes: MetadataRoute.Sitemap = [
    { url: origin, lastModified: new Date() },
    { url: `${origin}/terms`, lastModified: new Date() },
    { url: `${origin}/privacy`, lastModified: new Date() },
    { url: `${origin}/about-us`, lastModified: new Date() },
    { url: `${origin}/refund-policy`, lastModified: new Date() },
    { url: `${origin}/track-order`, lastModified: new Date() },
  ];

  const gameRoutes: MetadataRoute.Sitemap = games.map((game) => ({
    url: `${origin}/order/${game.slug}`,
    lastModified: game.addedAt ? new Date(game.addedAt) : new Date(),
  }));

  // ADR-091: only listed when the page actually renders on this brand —
  // same "empty tiers = no page" signal as /price-list and SiteFooter.
  if (priceListEnabled) {
    staticRoutes.push({ url: `${origin}/price-list`, lastModified: new Date() });
  }

  // ADR-123 decision 10: the Game ID Checker is listed on the primary
  // brand only; an affiliate's pages work but carry noindex.
  if (playerCheck.indexable && playerCheck.families.length > 0) {
    staticRoutes.push({ url: `${origin}/check-id`, lastModified: new Date() });
    for (const family of playerCheck.families) {
      staticRoutes.push({ url: `${origin}/check-id/${family.slug}`, lastModified: new Date() });
    }
  }

  // ADR-126 decision 2: only where /joki renders on this brand.
  if (jokiCatalog !== null) {
    staticRoutes.push({ url: `${origin}/joki`, lastModified: new Date() });
  }

  return [...staticRoutes, ...gameRoutes];
}
