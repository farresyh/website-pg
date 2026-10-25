import type { JokiTier } from "./joki";

/**
 * One tier as `PUT /api/joki-admin/tiers` takes it: only the shape fields of
 * its own kind (divisions + stars for Epic/Legend, the Mythic start for
 * Mythic and up). The API refuses a null shape field, so sending every
 * field for every tier failed the whole save (2026-10-25).
 */
export function tierUpdate(t: JokiTier) {
  const shape = t.kind === "divisional"
    ? { divisions: t.divisions, stars_per_division: t.stars_per_division }
    : { mythic_from_stars: t.mythic_from_stars };
  return { id: t.id, name: t.name, rate_rm: t.rate_rm, express_rate_rm: t.express_rate_rm, icon_url: t.icon_url || null, ...shape };
}
