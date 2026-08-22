import { apiFetch } from "@/lib/api-client";

/**
 * ADR-028 + its 2026-08-22 addendum — real store branding/footer/legal
 * content, replacing SiteFooter.tsx's hardcoded FOOTER_COLUMNS/
 * SOCIAL_LINKS and the three previously-nonexistent legal pages.
 * `{store_name}` is already substituted server-side (BrandingController)
 * — nothing here re-does that.
 */

interface BrandingFooterGameWire {
  id: number;
  name: string;
  slug: string;
}

interface BrandingWire {
  store_name: string;
  description: string | null;
  support_email: string | null;
  support_phone: string | null;
  telegram_contact_link: string | null;
  social_links: { facebook?: string; instagram?: string } | null;
  footer_text: string | null;
  footer_games: BrandingFooterGameWire[];
}

export interface Branding {
  storeName: string;
  description: string | null;
  supportEmail: string | null;
  supportPhone: string | null;
  socialLinks: { facebook?: string; instagram?: string };
  footerText: string | null;
  footerGames: { id: number; name: string; slug: string }[];
}

export async function getBranding(): Promise<Branding> {
  const wire = await apiFetch<BrandingWire>("/api/catalog/branding");

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

/** Already sanitized + `{store_name}`-substituted server-side — safe to render as-is. */
export async function getLegalContent(page: LegalPage): Promise<string | null> {
  const wire = await apiFetch<{ content: string | null }>(`/api/catalog/legal/${page}`);
  return wire.content;
}
