import { apiFetch } from "@/lib/api-client";

/**
 * ADR-029 — public SEO data consumed server-side: settings/templates/
 * pixel IDs for generateMetadata()/layout scripts, redirects for
 * proxy.ts's in-memory cache (decision 9 — Next.js 16 renamed
 * middleware.ts to proxy.ts, confirmed against this project's own
 * bundled docs per storefront/AGENTS.md), scripts for layout
 * injection, crawler rules for app/robots.ts.
 */

export interface SeoSettings {
  default_meta_title: string | null;
  default_meta_description: string | null;
  default_og_image: string | null;
  meta_title_template: string | null;
  meta_description_template: string | null;
  ga_measurement_id: string | null;
  fb_pixel_id: string | null;
  tiktok_pixel_id: string | null;
  schema_organization_enabled: boolean;
  schema_product_enabled: boolean;
  schema_breadcrumb_enabled: boolean;
}

export interface SeoScriptWire {
  location: "head" | "body_end";
  code: string;
  priority: number;
}

export interface CrawlerRuleWire {
  bot_name: string;
  user_agent: string;
  is_allowed: boolean;
  crawl_delay: number | null;
  disallow_paths: string[] | null;
}

/**
 * No `next: { revalidate }` on any of these three — every call site
 * (root layout, `/order/[slug]`, `app/robots.ts`, `app/sitemap.ts`) is
 * already a per-request dynamic route, so a fetch-level cache on top
 * would only add stale-for-up-to-60s staleness on top of Laravel's own
 * `Cache::remember()` (which is itself invalidated immediately on an
 * admin save via `forgetCache()`/`forgetRobotsCache()`) — confirmed
 * live: without this, an admin-added crawler rule didn't show up on
 * `/robots.txt` for up to a minute despite the backend already
 * serving the fresh row.
 */
export async function getSeoSettings(): Promise<SeoSettings> {
  return apiFetch<SeoSettings>("/api/catalog/seo/settings");
}

export async function getSeoScripts(): Promise<SeoScriptWire[]> {
  return apiFetch<SeoScriptWire[]>("/api/catalog/seo/scripts");
}

export async function getCrawlerRules(): Promise<CrawlerRuleWire[]> {
  return apiFetch<CrawlerRuleWire[]>("/api/catalog/seo/robots");
}

/**
 * ADR-028 addendum decision 13 / ADR-029 addendum decision 8: the one
 * template convention this codebase uses everywhere — only the exact
 * literal tokens are replaced, any other brace sequence (a typo, an
 * unrecognized token) is left verbatim, no error.
 */
export function renderTemplate(template: string, tokens: Record<string, string>): string {
  return Object.entries(tokens).reduce(
    (result, [key, value]) => result.split(`{${key}}`).join(value),
    template,
  );
}
