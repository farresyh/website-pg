import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * Real request/response contract for the public hero-banner listing
 * (HeroSlideController; see docs/prd.md §15 "Storefront" / "SEO" rows) —
 * replaces placeholder-data.ts's HERO_SLIDES.
 * Same snake_case-wire / camelCase-UI translation pattern as catalog.ts.
 *
 * ADR-044: schema is the source of truth for the wire shape below.
 */

const HeroSlideWireSchema = z.object({
  id: z.number(),
  eyebrow: z.string().nullable(),
  title: z.string().nullable(),
  description: z.string().nullable(),
  image_url: z.string().nullable(),
  price_from_sen: z.number().nullable(),
  primary_cta_label: z.string().nullable(),
  primary_cta_href: z.string().nullable(),
  secondary_cta_label: z.string().nullable(),
  secondary_cta_href: z.string().nullable(),
});

type HeroSlideWire = z.infer<typeof HeroSlideWireSchema>;

export interface HeroSlide {
  id: number;
  eyebrow: string | null;
  title: string | null;
  description: string | null;
  imageUrl: string | null;
  priceFromRm: number | null;
  primaryCta: { label: string; href: string } | null;
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
    primaryCta:
      wire.primary_cta_label && wire.primary_cta_href
        ? { label: wire.primary_cta_label, href: wire.primary_cta_href }
        : null,
    secondaryCta:
      wire.secondary_cta_label && wire.secondary_cta_href
        ? { label: wire.secondary_cta_label, href: wire.secondary_cta_href }
        : null,
  };
}

export async function listHeroSlides(): Promise<HeroSlide[]> {
  const path = "/api/catalog/hero-slides";
  return safeRead(
    "listHeroSlides",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      const wire = parseResponse(z.array(HeroSlideWireSchema), raw, "HeroSlideWire[]", path);
      return wire.map(toHeroSlide);
    },
    [],
  );
}
