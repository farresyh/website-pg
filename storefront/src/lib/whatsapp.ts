import type { Branding } from "@/lib/branding";

/**
 * Resolve the storefront's WhatsApp support link from admin Store
 * Branding (ADR-071 PR0). Priority: an explicit `social_links.whatsapp`
 * URL, else a `wa.me/` link built from `support_phone` (digits only —
 * `+65 8275 1992` → `https://wa.me/6582751992`). Returns null when
 * neither is configured, so callers can hide the entry point rather
 * than render a dead link (the old hardcoded `wa.me/60000000000`).
 */
export function resolveWhatsappHref(branding: Pick<Branding, "socialLinks" | "supportPhone">): string | null {
  const explicit = branding.socialLinks.whatsapp?.trim();
  if (explicit) return explicit;

  const digits = branding.supportPhone?.replace(/\D/g, "") ?? "";
  return digits.length > 0 ? `https://wa.me/${digits}` : null;
}
