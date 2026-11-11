/**
 * ADR-120 decision 4: the one resolver for every SEO text field. Tokens
 * (`{game_name}`, `{store_name}`) render in all of them — per-game,
 * template, default — because per-game SEO is global across brands
 * (ADR-029 decision 1), so `{store_name}` is the only way it stays
 * brand-correct. Dependency-free on purpose: pure string logic, no fetch.
 */

import { pageMeta } from "./page-meta.ts";

export interface SeoTextSettings {
  default_meta_title: string | null;
  default_meta_description: string | null;
  meta_title_template: string | null;
  meta_description_template: string | null;
}

type Tokens = Record<string, string>;

/**
 * ADR-028 addendum decision 13 / ADR-029 addendum decision 8: only the
 * exact literal tokens are replaced — any other brace sequence (a typo,
 * an unrecognized token) is left verbatim, no error.
 */
export function renderTemplate(template: string, tokens: Tokens): string {
  return Object.entries(tokens).reduce(
    (result, [key, value]) => result.split(`{${key}}`).join(value),
    template,
  );
}

/** First non-blank candidate, token-rendered; null when every one is blank. */
function firstRendered(candidates: (string | null | undefined)[], tokens: Tokens): string | null {
  for (const candidate of candidates) {
    if (candidate && candidate.trim() !== "") return renderTemplate(candidate, tokens);
  }
  return null;
}

/**
 * Storefront-wide meta (home, and the base every page inherits). The admin's
 * default fields win; when one is empty the registry's home entry fills it
 * (ADR-140 decision 1), so no page ever falls back to a sentence hardcoded here.
 */
export function resolveSiteMeta(settings: SeoTextSettings, storeName: string) {
  const tokens = { store_name: storeName };
  const home = pageMeta("home", storeName);
  return {
    title: firstRendered([settings.default_meta_title], tokens) ?? home.title,
    description: firstRendered([settings.default_meta_description], tokens) ?? home.description,
  };
}

/** The most a meta description is cut to; Google shows about this much. */
const META_DESCRIPTION_MAX = 155;

/**
 * Collapse whitespace, then cut at a word boundary inside `max` characters and
 * end with an ellipsis. A prefix of the text, never a rewrite. A single word
 * longer than `max` is hard-cut. Pure.
 */
export function truncateAtWord(text: string, max: number): string {
  const flat = text.replace(/\s+/g, " ").trim();
  if (flat.length <= max) return flat;

  const room = flat.slice(0, max - 1);
  const lastSpace = room.lastIndexOf(" ");
  const cut = lastSpace > 0 ? room.slice(0, lastSpace) : room;
  return `${cut.replace(/[\s,;:.\-–—]+$/, "")}…`;
}

/**
 * Per-game override → the game's own summary (ADR-140 decision 3: primary brand,
 * published only, so an affiliate never has one) → template → storefront default
 * → generic, all token-rendered.
 */
export function resolveGameMeta(
  game: { name: string; seoTitle: string | null; seoDescription: string | null; contentSummary?: string | null },
  settings: SeoTextSettings,
  storeName: string,
) {
  const tokens = { game_name: game.name, store_name: storeName };
  return {
    title:
      firstRendered([game.seoTitle, settings.meta_title_template, settings.default_meta_title], tokens) ??
      `Top Up ${game.name} - ${storeName}`,
    description:
      firstRendered(
        [
          game.seoDescription,
          game.contentSummary?.trim() ? truncateAtWord(game.contentSummary, META_DESCRIPTION_MAX) : null,
          settings.meta_description_template,
          settings.default_meta_description,
        ],
        tokens,
      ) ?? `Top up ${game.name} at ${storeName}. Fast, secure, guest checkout.`,
  };
}
