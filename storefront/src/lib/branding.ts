import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";
import { SITE_URL } from "@/lib/site";

/**
 * ADR-028 + its 2026-08-22 addendum — real store branding/footer/legal
 * content, replacing SiteFooter.tsx's hardcoded FOOTER_COLUMNS/
 * SOCIAL_LINKS and the three previously-nonexistent legal pages.
 * `{store_name}` is already substituted server-side (BrandingController)
 * — nothing here re-does that.
 *
 * ADR-044: schemas are the source of truth for the wire shapes below.
 */

const BrandingFooterGameWireSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
});

const BrandingWireSchema = z.object({
  store_name: z.string(),
  // ADR-120 decision 2: the brand's own public origin (its primary
  // domain), never the request host. Optional so an older backend parses.
  canonical_origin: z.url().optional(),
  description: z.string().nullable(),
  // ADR-060 PR-6: the brand's uploaded logo, derived server-side from
  // `affiliate_branding.logo_path` (a full URL on the backend host), or
  // null to fall back to the placeholder mark.
  logo_url: z.string().nullable(),
  favicon_url: z.string().nullable().optional(),
  theme_preset: z.string().nullable().optional(),
  theme_mode: z.enum(["light", "dark"]).nullable().optional(),
  support_email: z.string().nullable(),
  support_phone: z.string().nullable(),
  // ADR-116 decision 6 — the platform customer-support number for the
  // order status page's WhatsApp buttons. Optional so an older backend still parses.
  order_support_phone: z.string().nullable().optional(),
  telegram_contact_link: z.string().nullable(),
  social_links: z
    .object({
      facebook: z.string().optional(),
      instagram: z.string().optional(),
      tiktok: z.string().optional(),
      youtube: z.string().optional(),
      whatsapp: z.string().optional(),
    })
    .nullable(),
  footer_text: z.string().nullable(),
  footer_games: z.array(BrandingFooterGameWireSchema),
});

export interface Branding {
  storeName: string;
  /** No trailing slash. Every canonical/sitemap/robots/llms/JSON-LD URL starts here. */
  canonicalOrigin: string;
  description: string | null;
  logoUrl: string | null;
  faviconUrl: string | null;
  themePreset: string;
  themeMode: "light" | "dark";
  supportEmail: string | null;
  supportPhone: string | null;
  orderSupportPhone: string | null;
  socialLinks: { facebook?: string; instagram?: string; tiktok?: string; youtube?: string; whatsapp?: string };
  footerText: string | null;
  footerGames: { id: number; name: string; slug: string }[];
}

const BRANDING_FALLBACK: Branding = {
  storeName: "PekanGame",
  canonicalOrigin: SITE_URL,
  description: null,
  logoUrl: null,
  faviconUrl: null,
  themePreset: "default",
  themeMode: "light",
  supportEmail: null,
  supportPhone: null,
  orderSupportPhone: null,
  socialLinks: {},
  footerText: null,
  footerGames: [],
};

/**
 * ADR-071 PR1: consumed by the root layout and `SiteFooter`, so it
 * joins the `catalog` Data-Cache tag (60s interim TTL, purged on an
 * admin branding save by the PR2 webhook). The fallback keeps a
 * backend blip — or a backend-less CI build — from throwing.
 */
export async function getBranding(): Promise<Branding> {
  const path = "/api/catalog/branding";
  return safeRead(
    "getBranding",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      const wire = parseResponse(BrandingWireSchema, raw, "BrandingWire", path);
      return {
        storeName: wire.store_name,
        canonicalOrigin: (wire.canonical_origin ?? SITE_URL).replace(/\/+$/, ""),
        description: wire.description,
        logoUrl: wire.logo_url,
        faviconUrl: wire.favicon_url ?? null,
        themePreset: wire.theme_preset ?? "default",
        themeMode: wire.theme_mode ?? "light",
        supportEmail: wire.support_email,
        supportPhone: wire.support_phone,
        orderSupportPhone: wire.order_support_phone ?? null,
        socialLinks: wire.social_links ?? {},
        footerText: wire.footer_text,
        footerGames: wire.footer_games,
      };
    },
    BRANDING_FALLBACK,
  );
}

export type LegalPage = "terms" | "privacy" | "about-us";

const LegalContentWireSchema = z.object({ content: z.string().nullable() });

/** Already sanitized + `{store_name}`-substituted server-side — safe to render as-is. */
export async function getLegalContent(page: LegalPage): Promise<string | null> {
  const path = `/api/catalog/legal/${page}`;
  return safeRead(
    `getLegalContent(${page})`,
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      const wire = parseResponse(LegalContentWireSchema, raw, "LegalContentWire", path);
      return wire.content;
    },
    null,
  );
}
