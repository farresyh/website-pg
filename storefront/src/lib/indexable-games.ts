/**
 * ADR-140 decision 4: the one rule for "this game is offered to crawlers". A
 * game the admin marks `noindex` is left out of the sitemap and `llms.txt`
 * alike, so neither lists a page that tells Google to stay away from it.
 * Dependency-free (pure logic), like page-meta.ts.
 */
export function indexableGames<T extends { noIndex: boolean }>(games: T[]): T[] {
  return games.filter((game) => !game.noIndex);
}
