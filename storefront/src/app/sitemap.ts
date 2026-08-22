import type { MetadataRoute } from "next";
import { listGames } from "@/lib/catalog";
import { SITE_URL } from "@/lib/site";

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
 * /order/[slug] page.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const staticRoutes: MetadataRoute.Sitemap = [
    { url: SITE_URL, lastModified: new Date() },
    { url: `${SITE_URL}/terms`, lastModified: new Date() },
    { url: `${SITE_URL}/privacy`, lastModified: new Date() },
    { url: `${SITE_URL}/about-us`, lastModified: new Date() },
    { url: `${SITE_URL}/track-order`, lastModified: new Date() },
  ];

  const games = await listGames();
  const gameRoutes: MetadataRoute.Sitemap = games.map((game) => ({
    url: `${SITE_URL}/order/${game.slug}`,
    lastModified: game.addedAt ? new Date(game.addedAt) : new Date(),
  }));

  return [...staticRoutes, ...gameRoutes];
}
