import { apiFetch } from "@/lib/api-client";

/**
 * Real request/response contract for the public hero-banner listing
 * (HeroSlideController, docs/prd.md §14/§15 backlog: "Hero Banner /
 * Campaign management") — replaces placeholder-data.ts's HERO_SLIDES.
 * Same snake_case-wire / camelCase-UI translation pattern as catalog.ts.
 */

interface HeroSlideWire {
  id: number;
  eyebrow: string | null;
  title: string;
  description: string | null;
  image_url: string | null;
  price_from_sen: number | null;
  primary_cta_label: string;
  primary_cta_href: string;
  secondary_cta_label: string | null;
  secondary_cta_href: string | null;
}

export interface HeroSlide {
  id: number;
  eyebrow: string | null;
  title: string;
  description: string | null;
  imageUrl: string | null;
  priceFromRm: number | null;
  primaryCta: { label: string; href: string };
  secondaryCta: { label: string; href: string } | null;
}

function toHeroSlide(wire: HeroSlideWire): HeroSlide {
  return {
    id: wire.id,
    eyebrow: wire.eyebrow,
    title: wire.title,
    description: wire.description,
    imageUrl: wire.image_url,
    priceFromRm: wire.price_from_sen != null ? wire.price_from_sen / 100 : null,
    primaryCta: { label: wire.primary_cta_label, href: wire.primary_cta_href },
    secondaryCta:
      wire.secondary_cta_label && wire.secondary_cta_href
        ? { label: wire.secondary_cta_label, href: wire.secondary_cta_href }
        : null,
  };
}

export async function listHeroSlides(): Promise<HeroSlide[]> {
  const wire = await apiFetch<HeroSlideWire[]>("/api/catalog/hero-slides");
  return wire.map(toHeroSlide);
}
