import { getBranding } from "@/lib/branding";
import { getSeoSettings } from "@/lib/seo";
import { listGames } from "@/lib/catalog";
import { resolveSiteMeta } from "@/lib/seo-meta";

/**
 * ADR-029 addendum 4 (2026-08-22, same session, founder request):
 * llms.txt (llmstxt.org convention) — a curated markdown digest for
 * LLM/AI-agent consumption, a genuinely different concern from
 * robots.txt (access control) despite both being crawler-facing.
 * Fully auto-generated from existing catalog/branding data, same
 * "no admin-authored field" choice app/sitemap.ts already made —
 * no new table, nothing to drift out of sync with the real catalog.
 * `/llms.txt` only (the concise root convention) — `/llms-full.txt`
 * deliberately not built, adoption for that variant isn't established
 * enough yet to justify it.
 *
 * force-dynamic for the same reason as robots.ts/sitemap.ts: a game
 * added/removed shouldn't need a rebuild to show up here.
 */
export const dynamic = "force-dynamic";

export async function GET() {
  const [branding, settings, games] = await Promise.all([getBranding(), getSeoSettings(), listGames()]);

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

  if (games.length > 0) {
    lines.push("## Games");
    for (const game of games) {
      const price = game.priceFromRm !== null ? ` - from RM${game.priceFromRm.toFixed(2)}` : "";
      const category = game.category ? ` (${game.category})` : "";
      lines.push(`- [${game.name}](${origin}/order/${game.slug})${category}${price}`);
    }
    lines.push("");
  }

  lines.push("## Pages");
  lines.push(`- [Track Order](${origin}/track-order): check the delivery status of a past order`);
  lines.push(`- [About Us](${origin}/about-us)`);
  lines.push(`- [Terms & Conditions](${origin}/terms)`);
  lines.push(`- [Privacy Policy](${origin}/privacy)`);

  return new Response(lines.join("\n") + "\n", {
    headers: { "Content-Type": "text/markdown; charset=utf-8" },
  });
}
