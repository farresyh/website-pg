import { indexableGames } from "./indexable-games.ts";

/**
 * ADR-029 addendum decision 16 + ADR-140 decision 4. The sitemap's rows as a
 * pure function, so the rules are testable without a backend: static routes,
 * the pages that render only on some brands, then one row per indexable game.
 *
 * `lastModified` is sent only when it is a real date. The old code stamped
 * "now" on every static route and on any undated game, which Google learns to
 * ignore. A game's date is the one the catalog gives it (ADR-140 PR 2 moves it
 * to the content's publish date when there is one); a price change never
 * moves it, because prices change daily and that would be a lie again.
 */
export interface SitemapGame {
  slug: string;
  /** ISO date, or "" when the catalog has none. */
  addedAt: string;
  /** ADR-140 decision 4: when the game's content was last published (primary brand only). Wins over `addedAt`. */
  contentPublishedAt?: string | null;
  noIndex: boolean;
}

export interface SitemapInput {
  /** The serving brand's own canonical origin, no trailing slash (ADR-120). */
  origin: string;
  games: SitemapGame[];
  /** ADR-091: the reseller price list renders on this brand. */
  priceList: boolean;
  /** ADR-123: family slugs when the Game ID Checker is indexable here, else []. */
  checkIdSlugs: string[];
  /** ADR-126: /joki renders on this brand. */
  joki: boolean;
}

export interface SitemapEntry {
  url: string;
  lastModified?: Date;
}

export function buildSitemapEntries({ origin, games, priceList, checkIdSlugs, joki }: SitemapInput): SitemapEntry[] {
  const entries: SitemapEntry[] = [
    { url: origin },
    { url: `${origin}/terms` },
    { url: `${origin}/privacy` },
    { url: `${origin}/about-us` },
    { url: `${origin}/refund-policy` },
    { url: `${origin}/track-order` },
  ];

  if (priceList) entries.push({ url: `${origin}/price-list` });

  if (checkIdSlugs.length > 0) {
    entries.push({ url: `${origin}/check-id` });
    for (const slug of checkIdSlugs) entries.push({ url: `${origin}/check-id/${slug}` });
  }

  if (joki) entries.push({ url: `${origin}/joki` });

  for (const game of indexableGames(games)) {
    const date = game.contentPublishedAt || game.addedAt;
    entries.push({ url: `${origin}/order/${game.slug}`, ...(date ? { lastModified: new Date(date) } : {}) });
  }

  return entries;
}
