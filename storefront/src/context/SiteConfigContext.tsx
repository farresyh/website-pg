"use client";

import { createContext, useContext, type ReactNode } from "react";
import type { Branding } from "@/lib/branding";
import { resolveWhatsappHref } from "@/lib/whatsapp";

/**
 * ADR-071 PR1 decision 5 — storefront-wide config the chrome needs
 * (`SiteHeader`, `BottomNav`, `SiteFooter`), resolved once server-side
 * in the root layout and handed down, instead of `SiteHeader` and
 * `BottomNav` each firing their own `listPlans()` / `getBranding()`
 * from a `useEffect` on every navigation.
 *
 *  - `membershipEnabled` — the ADR-055 dual kill switch (`listPlans()`
 *    returned rows).
 *  - `branding` — the admin Store Branding record.
 *  - `whatsappHref` — the resolved support link (ADR-071 PR0), or null
 *    when no WhatsApp target is configured.
 *  - `orderWhatsappHref` — ADR-116 decision 6: the order status page's
 *    WhatsApp link, always the platform customer-support number (the one
 *    OpenWA reads), whatever the brand. Falls back to `whatsappHref`.
 */
interface SiteConfig {
  membershipEnabled: boolean;
  branding: Branding;
  whatsappHref: string | null;
  orderWhatsappHref: string | null;
}

const SiteConfigContext = createContext<SiteConfig | null>(null);

export function SiteConfigProvider({
  value,
  children,
}: {
  value: { membershipEnabled: boolean; branding: Branding };
  children: ReactNode;
}) {
  const whatsappHref = resolveWhatsappHref(value.branding);
  const orderDigits = value.branding.orderSupportPhone?.replace(/\D/g, "") ?? "";
  const config: SiteConfig = {
    ...value,
    whatsappHref,
    orderWhatsappHref: orderDigits ? `https://wa.me/${orderDigits}` : whatsappHref,
  };
  return <SiteConfigContext.Provider value={config}>{children}</SiteConfigContext.Provider>;
}

export function useSiteConfig(): SiteConfig {
  const ctx = useContext(SiteConfigContext);
  if (!ctx) throw new Error("useSiteConfig must be used within SiteConfigProvider");
  return ctx;
}
