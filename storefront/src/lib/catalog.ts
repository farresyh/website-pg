import { z } from "zod";
import { apiFetch, ApiError } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real request/response contracts for the public catalog endpoints
 * (CatalogController, docs/prd.md §14/§15 NEXT SESSION pointer) — the
 * replacement for placeholder-data.ts's PLACEHOLDER_GAMES/
 * PLACEHOLDER_PACKAGES. Wire shapes below are snake_case exactly as
 * the backend returns them (same convention as checkout.ts); the
 * `Game`/`GamePackage` types are the UI-facing shape every component
 * already consumes (camelCase), so this file is the one place doing
 * the translation — no component needed to change field names.
 *
 * ADR-044: schemas are the source of truth for the wire shapes below
 * (`z.infer`), not separately hand-written interfaces.
 */

const CatalogGameWireSchema = z.object({
  id: z.number(),
  slug: z.string(),
  name: z.string(),
  category: z.string().nullable(),
  image_url: z.string().nullable(),
  banner_url: z.string().nullable().optional(),
  extra_field: z.enum(["server_id", "zone_id"]).nullable(),
  player_validator_enabled: z.boolean(),
  price_from_sen: z.number().nullable().optional(),
  created_at: z.string().optional(),
  seo_title: z.string().nullable().optional(),
  seo_description: z.string().nullable().optional(),
  seo_og_image: z.string().nullable().optional(),
  schema_brand: z.string().nullable().optional(),
  schema_category: z.string().nullable().optional(),
  no_index: z.boolean().optional(),
});

type CatalogGameWire = z.infer<typeof CatalogGameWireSchema>;

const CatalogPackageWireSchema = z.object({
  id: z.number(),
  name: z.string(),
  selling_price_sen: z.number(),
});

type CatalogPackageWire = z.infer<typeof CatalogPackageWireSchema>;

export interface Game {
  id: number;
  slug: string;
  name: string;
  category: string;
  imageUrl: string | null;
  /** No `publisher`/`developer` column exists on the real Game model — always undefined, never fabricated. */
  publisher?: string;
  /** RM, null when the game has no active packages yet — render sites must handle this, unlike the old placeholder's always-present number. */
  priceFromRm: number | null;
  extraField: "server_id" | "zone_id" | null;
  playerValidatorEnabled: boolean;
  /** Mirrors games.created_at — powers NewArrivalsSection's client-side sort. */
  addedAt: string;
}

export interface GameDetail extends Game {
  seoTitle: string | null;
  seoDescription: string | null;
  seoOgImage: string | null;
  schemaBrand: string | null;
  schemaCategory: string | null;
  noIndex: boolean;
}

export interface GamePackage {
  id: number;
  name: string;
  priceRm: number;
}

function toGame(wire: CatalogGameWire): Game {
  return {
    id: wire.id,
    slug: wire.slug,
    name: wire.name,
    category: wire.category ?? "",
    imageUrl: wire.image_url,
    priceFromRm: wire.price_from_sen != null ? wire.price_from_sen / 100 : null,
    extraField: wire.extra_field,
    playerValidatorEnabled: wire.player_validator_enabled,
    addedAt: wire.created_at ?? "",
  };
}

function toGameDetail(wire: CatalogGameWire): GameDetail {
  return {
    ...toGame(wire),
    seoTitle: wire.seo_title ?? null,
    seoDescription: wire.seo_description ?? null,
    seoOgImage: wire.seo_og_image ?? null,
    schemaBrand: wire.schema_brand ?? null,
    schemaCategory: wire.schema_category ?? null,
    noIndex: wire.no_index ?? false,
  };
}

function toPackage(wire: CatalogPackageWire): GamePackage {
  return { id: wire.id, name: wire.name, priceRm: wire.selling_price_sen / 100 };
}

export async function listGames(): Promise<Game[]> {
  const path = "/api/catalog/games";
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(z.array(CatalogGameWireSchema), raw, "CatalogGameWire[]", path);
  return wire.map(toGame);
}

export async function getGame(slug: string): Promise<GameDetail | null> {
  try {
    const path = `/api/catalog/games/${encodeURIComponent(slug)}`;
    const raw = await apiFetch<unknown>(path);
    const wire = parseResponse(CatalogGameWireSchema, raw, "CatalogGameWire", path);
    return toGameDetail(wire);
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) return null;
    throw err;
  }
}

export async function getGamePackages(slug: string): Promise<GamePackage[]> {
  const path = `/api/catalog/games/${encodeURIComponent(slug)}/packages`;
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(z.array(CatalogPackageWireSchema), raw, "CatalogPackageWire[]", path);
  return wire.map(toPackage);
}

/** Quick Counter's shortlist — a small, hand-picked subset of real games by slug (editorial curation, not backend data). */
export const QUICK_COUNTER_SLUGS = ["mobile-legends", "pubg-mobile", "honor-of-kings", "free-fire"];
