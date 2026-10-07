/**
 * ADR-120 decision 4: the one resolver for every SEO text field. Tokens
 * (`{game_name}`, `{store_name}`) render in all of them — per-game,
 * template, default — because per-game SEO is global across brands
 * (ADR-029 decision 1), so `{store_name}` is the only way it stays
 * brand-correct. Dependency-free on purpose: pure string logic, no fetch.
 */

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

/** Storefront-wide meta (home, and the base every page inherits). */
export function resolveSiteMeta(settings: SeoTextSettings, storeName: string) {
  const tokens = { store_name: storeName };
  return {
    title: firstRendered([settings.default_meta_title], tokens) ?? `${storeName} - Top Up Games in Malaysia`,
    description:
      firstRendered([settings.default_meta_description], tokens) ??
      `Fast, secure game top-ups at ${storeName}. Delivered in 3 minutes.`,
  };
}

/** Per-game override → template → storefront default → generic, all token-rendered. */
export function resolveGameMeta(
  game: { name: string; seoTitle: string | null; seoDescription: string | null },
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
        [game.seoDescription, settings.meta_description_template, settings.default_meta_description],
        tokens,
      ) ?? `Top up ${game.name} at ${storeName}. Fast, secure, guest checkout.`,
  };
}
