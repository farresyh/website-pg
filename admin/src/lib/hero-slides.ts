import { apiFetch } from "@/lib/api-client";

/**
 * Hero Banner / Campaign management (see docs/prd.md §15 "Storefront" row).
 * Deliberately separate from a future Promotions feature (founder
 * decision, 2026-07-26) — a hero slide is marketing copy an admin
 * authors directly (image + text + CTA links), never tied to a real
 * Game/Package or a computed price.
 */
export interface HeroSlide {
  id: number;
  eyebrow: string | null;
  title: string;
  description: string | null;
  image_url: string | null;
  /** Sen — admin-typed display copy ("Starting at RM X"), never a live/computed price. */
  price_from_sen: number | null;
  primary_cta_label: string;
  primary_cta_href: string;
  secondary_cta_label: string | null;
  secondary_cta_href: string | null;
  is_active: boolean;
  sort_order: number;
  starts_at: string | null;
  ends_at: string | null;
}

export interface SaveHeroSlideValues {
  eyebrow?: string | null;
  title: string;
  description?: string | null;
  image_url?: string | null;
  price_from_sen?: number | null;
  primary_cta_label: string;
  primary_cta_href: string;
  secondary_cta_label?: string | null;
  secondary_cta_href?: string | null;
  is_active: boolean;
  sort_order: number;
  starts_at?: string | null;
  ends_at?: string | null;
}

export function listHeroSlides(token: string) {
  return apiFetch<HeroSlide[]>("/api/hero-slides", { token });
}

export function createHeroSlide(token: string, values: SaveHeroSlideValues) {
  return apiFetch<HeroSlide>("/api/hero-slides", { method: "POST", token, body: values });
}

export function updateHeroSlide(token: string, id: number, values: SaveHeroSlideValues) {
  return apiFetch<HeroSlide>(`/api/hero-slides/${id}`, { method: "PUT", token, body: values });
}

export function updateHeroSlideStatus(token: string, id: number, isActive: boolean) {
  return apiFetch<HeroSlide>(`/api/hero-slides/${id}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

export function deleteHeroSlide(token: string, id: number) {
  return apiFetch<void>(`/api/hero-slides/${id}`, { method: "DELETE", token });
}
