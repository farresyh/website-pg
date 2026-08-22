import type { MetadataRoute } from "next";
import { getCrawlerRules } from "@/lib/seo";
import { SITE_URL } from "@/lib/site";

/** Same request-time reasoning as app/sitemap.ts — an admin-added crawler rule shouldn't need a rebuild to take effect. */
export const dynamic = "force-dynamic";

/**
 * ADR-029 addendum decision 14 — native app/robots.ts (confirmed
 * against this project's own bundled Next.js 16 docs per storefront/
 * AGENTS.md), driven by the admin-editable crawler_rules table. An
 * empty/unreachable backend falls back to allow-all rather than
 * erroring — a cold start after deploy shouldn't leave /robots.txt
 * broken.
 */
export default async function robots(): Promise<MetadataRoute.Robots> {
  let rules: MetadataRoute.Robots["rules"] = { userAgent: "*", allow: "/" };

  try {
    const crawlerRules = await getCrawlerRules();
    if (crawlerRules.length > 0) {
      rules = crawlerRules.map((rule) =>
        rule.is_allowed
          ? {
              userAgent: rule.user_agent,
              allow: "/",
              ...(rule.disallow_paths && rule.disallow_paths.length > 0 ? { disallow: rule.disallow_paths } : {}),
              ...(rule.crawl_delay != null ? { crawlDelay: rule.crawl_delay } : {}),
            }
          : {
              userAgent: rule.user_agent,
              disallow: "/",
              ...(rule.crawl_delay != null ? { crawlDelay: rule.crawl_delay } : {}),
            },
      );
    }
  } catch {
    // Backend unreachable at build/request time — allow-all fallback above stands.
  }

  return {
    rules,
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
