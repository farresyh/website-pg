import { apiFetch } from "@/lib/api-client";

/** ADR-028 + its 2026-08-22 addendum — the Settings screen's three tabs. */

export interface Branding {
  id: number;
  reseller_id: number;
  store_name: string;
  description: string | null;
  support_email: string | null;
  support_phone: string | null;
  telegram_contact_link: string | null;
  social_links: { facebook?: string; instagram?: string; tiktok?: string; youtube?: string; whatsapp?: string } | null;
}

export interface FooterSettings {
  id: number;
  reseller_id: number;
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
  telegram_notifications_enabled: boolean;
  telegram_bot_token: string | null;
  telegram_chat_id: string | null;
  membership_enabled: boolean;
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
  | "telegram_notifications_enabled"
  | "telegram_bot_token"
  | "telegram_chat_id"
>;

export function getSettings(token: string) {
  return apiFetch<SettingsIndexResponse>("/api/settings", { token });
}

export function updateBranding(token: string, values: UpdateBrandingValues) {
  return apiFetch<Branding>("/api/settings/branding", { method: "PUT", token, body: values });
}

export function updateFooterSettings(token: string, values: UpdateFooterValues) {
  return apiFetch<FooterSettings>("/api/settings/footer", { method: "PUT", token, body: values });
}

export function updatePlatformSettings(token: string, values: UpdatePlatformValues) {
  return apiFetch<PlatformSettings>("/api/settings/platform", { method: "PUT", token, body: values });
}

export function applyBulkMarkup(token: string, markupPercent: number) {
  return apiFetch<{ packages_updated: number }>("/api/settings/platform/bulk-markup", {
    method: "POST",
    token,
    body: { markup_percent: markupPercent },
  });
}
