import type { MetadataRoute } from "next";
import { listGames } from "@/lib/catalog";
import { getResellerPriceList } from "@/lib/reseller-price-list";
import { getBranding } from "@/lib/branding";

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
  const [branding, games, resellerPriceList] = await Promise.all([getBranding(), listGames(), getResellerPriceList()]);
  const origin = branding.canonicalOrigin;

  const staticRoutes: MetadataRoute.Sitemap = [
    { url: origin, lastModified: new Date() },
    { url: `${origin}/terms`, lastModified: new Date() },
    { url: `${origin}/privacy`, lastModified: new Date() },
    { url: `${origin}/about-us`, lastModified: new Date() },
    { url: `${origin}/track-order`, lastModified: new Date() },
  ];

  const gameRoutes: MetadataRoute.Sitemap = games.map((game) => ({
    url: `${origin}/order/${game.slug}`,
    lastModified: game.addedAt ? new Date(game.addedAt) : new Date(),
  }));

  // ADR-091: only listed when the page actually renders on this brand —
  // same "empty tiers = no page" signal as /price-list and SiteFooter.
  if (resellerPriceList.tiers.length > 0) {
    staticRoutes.push({ url: `${origin}/price-list`, lastModified: new Date() });
  }

  return [...staticRoutes, ...gameRoutes];
}
