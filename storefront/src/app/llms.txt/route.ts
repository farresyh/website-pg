import { getBranding } from "@/lib/branding";
import { getSeoSettings } from "@/lib/seo";
import { listGames } from "@/lib/catalog";
import { listPlayerCheckFamilies } from "@/lib/player-check";
import { resolveSiteMeta } from "@/lib/seo-meta";
import { indexableGames } from "@/lib/indexable-games";
import { gameLine } from "@/lib/llms-lines";

/**
 * ADR-029 addendum 4 (2026-08-22, same session, founder request):
 * llms.txt (llmstxt.org convention) — a curated markdown digest for
 * LLM/AI-agent consumption, a genuinely different concern from
 * robots.txt (access control) despite both being crawler-facing.
 * Auto-generated from existing catalog/branding data, so nothing drifts
 * out of sync with the real catalog. The one admin-authored text is a
 * game's published summary (ADR-140 decision 10, an exception to ADR-043
 * decision 22), which the backend sends for the primary brand only.
 * `/llms.txt` only (the concise root convention) — `/llms-full.txt`
 * deliberately not built, adoption for that variant isn't established
 * enough yet to justify it.
 *
 * force-dynamic for the same reason as robots.ts/sitemap.ts: a game
 * added/removed shouldn't need a rebuild to show up here.
 */
export const dynamic = "force-dynamic";

export async function GET() {
  const [branding, settings, games, playerCheck] = await Promise.all([
    getBranding(),
    getSeoSettings(),
    listGames(),
    listPlayerCheckFamilies(),
  ]);

  // ADR-120 decisions 1 + 4: the serving brand's own origin, tokens rendered.
  const origin = branding.canonicalOrigin;
  const summary = settings.default_meta_description
    ? resolveSiteMeta(settings, branding.storeName).description
    : branding.description || `${branding.storeName}: fast, secure game top-ups.`;

  const lines: string[] = [];
  lines.push(`# ${branding.storeName}`);
  lines.push("");
  lines.push(`> ${summary}`);
  lines.push("");

  // ADR-140 decision 4: the same rule as the sitemap, so a noindex game is not listed.
  const listedGames = indexableGames(games);
  if (listedGames.length > 0) {
    lines.push("## Games");
    for (const game of listedGames) {
      lines.push(
        gameLine({
          origin,
          name: game.name,
          slug: game.slug,
          category: game.category,
          priceFromRm: game.priceFromRm,
          summary: game.contentSummary,
        }),
      );
    }
    lines.push("");
  }

  // ADR-123 decision 10: primary brand only, same rule as the sitemap.
  if (playerCheck.indexable && playerCheck.families.length > 0) {
    lines.push("## Game ID Checker");
    lines.push(`- [Game ID Checker](${origin}/check-id): free lookup of a game account's nickname and the store that tops it up`);
    for (const family of playerCheck.families) {
      lines.push(`- [${family.name} ID Checker](${origin}/check-id/${family.slug})`);
    }
    lines.push("");
  }

  lines.push("## Pages");
  lines.push(`- [Track Order](${origin}/track-order): check the delivery status of a past order`);
  lines.push(`- [About Us](${origin}/about-us)`);
  lines.push(`- [Terms & Conditions](${origin}/terms)`);
  lines.push(`- [Refund Policy](${origin}/refund-policy)`);
  lines.push(`- [Privacy Policy](${origin}/privacy)`);

  return new Response(lines.join("\n") + "\n", {
    headers: { "Content-Type": "text/markdown; charset=utf-8" },
  });
}
