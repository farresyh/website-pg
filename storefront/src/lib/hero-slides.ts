import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real request/response contract for the public hero-banner listing
 * (HeroSlideController, docs/prd.md §14/§15 backlog: "Hero Banner /
 * Campaign management") — replaces placeholder-data.ts's HERO_SLIDES.
 * Same snake_case-wire / camelCase-UI translation pattern as catalog.ts.
 *
 * ADR-044: schema is the source of truth for the wire shape below.
 */

const HeroSlideWireSchema = z.object({
  id: z.number(),
  eyebrow: z.string().nullable(),
  title: z.string(),
  description: z.string().nullable(),
  image_url: z.string().nullable(),
  price_from_sen: z.number().nullable(),
  primary_cta_label: z.string(),
  primary_cta_href: z.string(),
  secondary_cta_label: z.string().nullable(),
  secondary_cta_href: z.string().nullable(),
});

type HeroSlideWire = z.infer<typeof HeroSlideWireSchema>;

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
  const path = "/api/catalog/hero-slides";
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(z.array(HeroSlideWireSchema), raw, "HeroSlideWire[]", path);
  return wire.map(toHeroSlide);
}
