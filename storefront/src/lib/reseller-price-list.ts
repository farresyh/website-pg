import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-091: the public Reseller Price List page's data — a sales/
 * acquisition surface shown only on `is_owned` brands (the backend's
 * own job to check; this client has no `is_owned` concept, it just
 * reads whatever `[]`-or-not shape comes back, same "empty means off"
 * contract `listPlans()` already uses for Membership). ADR-044:
 * schemas are the source of truth for the wire shape below.
 *
 * 2026-11-01 addendum: the list is grouped (family → game → package) and
 * carries the apply link; the layout and sitemap, which only ask "is the
 * page on", use `getResellerPriceListEnabled()` (tiers alone) so they never
 * pull and parse every row on every page.
 */

const ResellerPriceListTierWireSchema = z.object({
  id: z.number(),
  name: z.string(),
});

const ResellerPriceListPriceWireSchema = z.object({
  tier_id: z.number(),
  price_sen: z.number(),
});

const ResellerPriceListPackageWireSchema = z.object({
  name: z.string(),
  prices: z.array(ResellerPriceListPriceWireSchema),
});

const ResellerPriceListGameWireSchema = z.object({
  name: z.string(),
  region: z.string(),
  packages: z.array(ResellerPriceListPackageWireSchema),
});

const ResellerPriceListGroupWireSchema = z.object({
  family: z.string(),
  image_url: z.string().nullable(),
  games: z.array(ResellerPriceListGameWireSchema),
});

const ResellerPriceListWireSchema = z.object({
  tiers: z.array(ResellerPriceListTierWireSchema),
  groups: z.array(ResellerPriceListGroupWireSchema),
  // Absent when the page is off (the backend answers tiers/groups empty).
  apply_url: z.string().optional(),
});

const ResellerPriceListSummaryWireSchema = z.object({
  tiers: z.array(ResellerPriceListTierWireSchema),
});

export interface ResellerPriceListTier {
  id: number;
  name: string;
}

export interface ResellerPriceListPackage {
  name: string;
  /** RM, keyed by tier id: same tiers array/order as ResellerPriceList.tiers. */
  pricesRmByTierId: Record<number, number>;
}

export interface ResellerPriceListGame {
  /** The game's full name ("Mobile Legends Malaysia"): what search matches. */
  name: string;
  /** The tab label inside its family ("Malaysia"); the game's name when it has none. */
  region: string;
  packages: ResellerPriceListPackage[];
}

/** One tile on the page: a family of regional games, or a single game on its own. */
export interface ResellerPriceListGroup {
  family: string;
  imageUrl: string | null;
  games: ResellerPriceListGame[];
}

export interface ResellerPriceList {
  tiers: ResellerPriceListTier[];
  groups: ResellerPriceListGroup[];
  /** Where "Apply to become a reseller" goes: the onboarding host, resolved by the backend. */
  applyUrl: string | null;
}

const PATH = "/api/catalog/reseller-price-list";

/**
 * `tiers: []` (no tier admin-marked `show_on_price_list`, or this brand
 * isn't `is_owned`) is the single "no page" signal — the caller
 * (`/price-list`) treats it as `notFound()`, same convention `/membership`
 * already follows for its own kill switch.
 */
export async function getResellerPriceList(): Promise<ResellerPriceList> {
  const empty: ResellerPriceList = { tiers: [], groups: [], applyUrl: null };

  return safeRead(
    "getResellerPriceList",
    async () => {
      const raw = await apiFetch<unknown>(PATH, { next: catalogCache });
      const wire = parseResponse(ResellerPriceListWireSchema, raw, "ResellerPriceListWire", PATH);

      return {
        tiers: wire.tiers,
        groups: wire.groups.map((group) => ({
          family: group.family,
          imageUrl: group.image_url,
          games: group.games.map((game) => ({
            name: game.name,
            region: game.region,
            packages: game.packages.map((pkg) => ({
              name: pkg.name,
              pricesRmByTierId: Object.fromEntries(pkg.prices.map((p) => [p.tier_id, p.price_sen / 100])),
            })),
          })),
        })),
        applyUrl: wire.apply_url ?? null,
      };
    },
    empty,
  );
}

/**
 * Whether `/price-list` exists for this brand. The root layout (bottom nav)
 * and the sitemap ask this on every render, so it takes the backend's
 * tiers-only answer instead of the whole list.
 */
export async function getResellerPriceListEnabled(): Promise<boolean> {
  const path = `${PATH}?summary=1`;

  return safeRead(
    "getResellerPriceListEnabled",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      return parseResponse(ResellerPriceListSummaryWireSchema, raw, "ResellerPriceListSummaryWire", path).tiers.length > 0;
    },
    false,
  );
}
