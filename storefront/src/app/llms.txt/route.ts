import { getBranding } from "@/lib/branding";
import { getSeoSettings } from "@/lib/seo";
import { listGames } from "@/lib/catalog";
import { SITE_URL } from "@/lib/site";

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

  const summary = settings.default_meta_description || branding.description || `${branding.storeName}: fast, secure game top-ups.`;

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
      lines.push(`- [${game.name}](${SITE_URL}/order/${game.slug})${category}${price}`);
    }
    lines.push("");
  }

  lines.push("## Pages");
  lines.push(`- [Track Order](${SITE_URL}/track-order): check the delivery status of a past order`);
  lines.push(`- [About Us](${SITE_URL}/about-us)`);
  lines.push(`- [Terms & Conditions](${SITE_URL}/terms)`);
  lines.push(`- [Privacy Policy](${SITE_URL}/privacy)`);

  return new Response(lines.join("\n") + "\n", {
    headers: { "Content-Type": "text/markdown; charset=utf-8" },
  });
}
