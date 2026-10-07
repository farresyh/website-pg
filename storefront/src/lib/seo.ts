import type { Metadata, ResolvingMetadata } from "next";
import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-029 — public SEO data consumed server-side: settings/templates/
 * pixel IDs for generateMetadata()/layout, redirects for proxy.ts's
 * in-memory cache (decision 9 — Next.js 16 renamed middleware.ts to
 * proxy.ts, confirmed against this project's own bundled docs per
 * storefront/AGENTS.md), crawler rules for app/robots.ts.
 * `getSeoScripts` (addendum 2 decision 13's admin-authored free-text
 * <script> feed) removed by ADR-101 decision 9.
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

const CrawlerRuleWireSchema = z.object({
  bot_name: z.string(),
  user_agent: z.string(),
  is_allowed: z.boolean(),
  crawl_delay: z.number().nullable(),
  disallow_paths: z.array(z.string()).nullable(),
});

export type CrawlerRuleWire = z.infer<typeof CrawlerRuleWireSchema>;

const SEO_SETTINGS_FALLBACK: SeoSettings = {
  default_meta_title: null,
  default_meta_description: null,
  default_og_image: null,
  meta_title_template: null,
  meta_description_template: null,
  ga_measurement_id: null,
  fb_pixel_id: null,
  tiktok_pixel_id: null,
  schema_organization_enabled: false,
  schema_product_enabled: false,
  schema_breadcrumb_enabled: false,
};

/**
 * ADR-071 PR1: `getSeoSettings` feeds the root layout,
 * which no longer carries `force-dynamic` (so `loading.tsx` and RSC
 * prefetch work). They join the shared `catalog` Data-Cache tag — an
 * admin SEO save purges it via the PR2 revalidation webhook (mirroring
 * Laravel's own `forgetCache()`), with the 60s TTL as the interim
 * freshness floor until that lands.
 *
 * `getCrawlerRules` stays uncached: `app/robots.ts` keeps
 * `force-dynamic` (route handler, per ADR-071), and a stale
 * `/robots.txt` was a real past incident — an admin-added crawler rule
 * took up to a minute to appear.
 */
export async function getSeoSettings(): Promise<SeoSettings> {
  const path = "/api/catalog/seo/settings";
  return safeRead(
    "getSeoSettings",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      return parseResponse(SeoSettingsSchema, raw, "SeoSettings", path);
    },
    SEO_SETTINGS_FALLBACK,
  );
}

export async function getCrawlerRules(): Promise<CrawlerRuleWire[]> {
  const path = "/api/catalog/seo/robots";
  return safeRead(
    "getCrawlerRules",
    async () => {
      const raw = await apiFetch<unknown>(path, { cache: "no-store" });
      return parseResponse(z.array(CrawlerRuleWireSchema), raw, "CrawlerRuleWire[]", path);
    },
    [],
  );
}

/**
 * ADR-120 decision 3: a page's canonical + og:url, as paths that the
 * root layout's `metadataBase` (the brand's canonical origin) resolves.
 * Setting `openGraph` on a page replaces the parent's whole object
 * (Next metadata merging), so the inherited OG image is carried over
 * unless the page passes its own.
 */
export async function canonicalMetadata(
  path: string,
  parent: ResolvingMetadata,
  ogImage?: string | null,
): Promise<Pick<Metadata, "alternates" | "openGraph">> {
  const inheritedImages = (await parent).openGraph?.images ?? [];
  return {
    alternates: { canonical: path },
    openGraph: { url: path, images: ogImage ? [{ url: ogImage }] : inheritedImages },
  };
}

/**
 * Wave 3 S-1: JSON.stringify alone doesn't escape `<`, so an
 * affiliate-controlled `store_name` of `</script><script>…` breaks out
 * of an inline `<script type="application/ld+json">`. The unicode
 * escape is still valid JSON, so crawlers read the same value.
 */
export function jsonLdHtml(data: unknown): string {
  return JSON.stringify(data).replace(/</g, "\\u003c");
}
