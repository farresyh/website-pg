import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

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
  description: z.string().nullable(),
  support_email: z.string().nullable(),
  support_phone: z.string().nullable(),
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
  description: string | null;
  supportEmail: string | null;
  supportPhone: string | null;
  socialLinks: { facebook?: string; instagram?: string; tiktok?: string; youtube?: string; whatsapp?: string };
  footerText: string | null;
  footerGames: { id: number; name: string; slug: string }[];
}

export async function getBranding(): Promise<Branding> {
  const path = "/api/catalog/branding";
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(BrandingWireSchema, raw, "BrandingWire", path);

  return {
    storeName: wire.store_name,
    description: wire.description,
    supportEmail: wire.support_email,
    supportPhone: wire.support_phone,
    socialLinks: wire.social_links ?? {},
    footerText: wire.footer_text,
    footerGames: wire.footer_games,
  };
}

export type LegalPage = "terms" | "privacy" | "about-us";

const LegalContentWireSchema = z.object({ content: z.string().nullable() });

/** Already sanitized + `{store_name}`-substituted server-side — safe to render as-is. */
export async function getLegalContent(page: LegalPage): Promise<string | null> {
  const path = `/api/catalog/legal/${page}`;
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(LegalContentWireSchema, raw, "LegalContentWire", path);
  return wire.content;
}
