import { apiFetch, apiUpload } from "@/lib/api-client";

/** ADR-028 + its 2026-08-22 addendum — the Settings screen's three tabs. */

export interface Branding {
  id: number;
  affiliate_id: number;
  store_name: string;
  description: string | null;
  // ADR-089: primary brand's logo/favicon — same upload pipeline as the
  // affiliate portal's Branding tab, just admin-gated.
  logo_url: string | null;
  favicon_url: string | null;
  support_email: string | null;
  support_phone: string | null;
  telegram_contact_link: string | null;
  social_links: { facebook?: string; instagram?: string; tiktok?: string; youtube?: string; whatsapp?: string } | null;
}

export interface FooterSettings {
  id: number;
  affiliate_id: number;
  footer_text: string | null;
  terms_content: string | null;
  privacy_content: string | null;
  about_us_content: string | null;
  footer_game_ids: number[] | null;
}

export interface PlatformSettings {
  id: number;
  currency: string;
  vip_spend_threshold_sen: number;
  maintenance_mode: boolean;
  maintenance_message: string | null;
  membership_enabled: boolean;
  whatsapp_notifications_enabled: boolean;
}

export interface SettingsIndexResponse {
  branding: Branding;
  footer: FooterSettings;
  platform: PlatformSettings;
}

export type UpdateBrandingValues = Pick<
  Branding,
  "store_name" | "description" | "support_email" | "support_phone" | "telegram_contact_link" | "social_links"
>;

export type UpdateFooterValues = Pick<
  FooterSettings,
  "footer_text" | "terms_content" | "privacy_content" | "about_us_content" | "footer_game_ids"
>;

export type UpdatePlatformValues = Pick<
  PlatformSettings,
  | "maintenance_mode"
  | "maintenance_message"
  | "vip_spend_threshold_sen"
  | "whatsapp_notifications_enabled"
>;

export function getSettings() {
  return apiFetch<SettingsIndexResponse>("/api/settings");
}

export function updateBranding(values: UpdateBrandingValues) {
  return apiFetch<Branding>("/api/settings/branding", { method: "PUT", body: values });
}

export function uploadBrandingLogo(file: File) {
  const form = new FormData();
  form.append("image", file);
  return apiUpload<Branding>("/api/settings/branding/logo", form);
}

export function deleteBrandingLogo() {
  return apiFetch<Branding>("/api/settings/branding/logo", { method: "DELETE" });
}

export function uploadBrandingFavicon(file: File) {
  const form = new FormData();
  form.append("image", file);
  return apiUpload<Branding>("/api/settings/branding/favicon", form);
}

export function deleteBrandingFavicon() {
  return apiFetch<Branding>("/api/settings/branding/favicon", { method: "DELETE" });
}

export function updateFooterSettings(values: UpdateFooterValues) {
  return apiFetch<FooterSettings>("/api/settings/footer", { method: "PUT", body: values });
}

export function updatePlatformSettings(values: UpdatePlatformValues) {
  return apiFetch<PlatformSettings>("/api/settings/platform", { method: "PUT", body: values });
}

export function applyBulkMarkup(markupPercent: number) {
  return apiFetch<{ packages_updated: number }>("/api/settings/platform/bulk-markup", {
    method: "POST",
    body: { markup_percent: markupPercent },
  });
}
