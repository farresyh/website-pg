import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * ADR-029 — public SEO data consumed server-side: settings/templates/
 * pixel IDs for generateMetadata()/layout scripts, redirects for
 * proxy.ts's in-memory cache (decision 9 — Next.js 16 renamed
 * middleware.ts to proxy.ts, confirmed against this project's own
 * bundled docs per storefront/AGENTS.md), scripts for layout
 * injection, crawler rules for app/robots.ts.
 *
 * ADR-044: schemas are the source of truth for the wire shapes below.
 */

const SeoSettingsSchema = z.object({
  default_meta_title: z.string().nullable(),
  default_meta_description: z.string().nullable(),
  default_og_image: z.string().nullable(),
  meta_title_template: z.string().nullable(),
  meta_description_template: z.string().nullable(),
  ga_measurement_id: z.string().nullable(),
  fb_pixel_id: z.string().nullable(),
  tiktok_pixel_id: z.string().nullable(),
  schema_organization_enabled: z.boolean(),
  schema_product_enabled: z.boolean(),
  schema_breadcrumb_enabled: z.boolean(),
});

export type SeoSettings = z.infer<typeof SeoSettingsSchema>;

const SeoScriptWireSchema = z.object({
  location: z.enum(["head", "body_end"]),
  code: z.string(),
  priority: z.number(),
});

export type SeoScriptWire = z.infer<typeof SeoScriptWireSchema>;

const CrawlerRuleWireSchema = z.object({
  bot_name: z.string(),
  user_agent: z.string(),
  is_allowed: z.boolean(),
  crawl_delay: z.number().nullable(),
  disallow_paths: z.array(z.string()).nullable(),
});

export type CrawlerRuleWire = z.infer<typeof CrawlerRuleWireSchema>;

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
  const path = "/api/catalog/seo/settings";
  const raw = await apiFetch<unknown>(path);
  return parseResponse(SeoSettingsSchema, raw, "SeoSettings", path);
}

export async function getSeoScripts(): Promise<SeoScriptWire[]> {
  const path = "/api/catalog/seo/scripts";
  const raw = await apiFetch<unknown>(path);
  return parseResponse(z.array(SeoScriptWireSchema), raw, "SeoScriptWire[]", path);
}

export async function getCrawlerRules(): Promise<CrawlerRuleWire[]> {
  const path = "/api/catalog/seo/robots";
  const raw = await apiFetch<unknown>(path);
  return parseResponse(z.array(CrawlerRuleWireSchema), raw, "CrawlerRuleWire[]", path);
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
