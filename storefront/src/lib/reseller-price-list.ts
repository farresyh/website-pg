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
 */

const ResellerPriceListTierWireSchema = z.object({
  id: z.number(),
  name: z.string(),
});

const ResellerPriceListPriceWireSchema = z.object({
  tier_id: z.number(),
  price_sen: z.number(),
});

const ResellerPriceListGameWireSchema = z.object({
  game_name: z.string(),
  package_name: z.string(),
  prices: z.array(ResellerPriceListPriceWireSchema),
});

const ResellerPriceListWireSchema = z.object({
  tiers: z.array(ResellerPriceListTierWireSchema),
  games: z.array(ResellerPriceListGameWireSchema),
});

export interface ResellerPriceListTier {
  id: number;
  name: string;
}

export interface ResellerPriceListRow {
  gameName: string;
  packageName: string;
  /** RM, keyed by tier id — same tiers array/order as ResellerPriceList.tiers. */
  pricesRmByTierId: Record<number, number>;
}

export interface ResellerPriceList {
  tiers: ResellerPriceListTier[];
  rows: ResellerPriceListRow[];
}

/**
 * `tiers: []` (no tier admin-marked `show_on_price_list`, or this brand
 * isn't `is_owned`) is the single "no page" signal — the caller
 * (`/price-list`) treats it as `notFound()`, same convention `/membership`
 * already follows for its own kill switch.
 */
export async function getResellerPriceList(): Promise<ResellerPriceList> {
  const path = "/api/catalog/reseller-price-list";
  const empty: ResellerPriceList = { tiers: [], rows: [] };

  return safeRead(
    "getResellerPriceList",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      const wire = parseResponse(ResellerPriceListWireSchema, raw, "ResellerPriceListWire", path);

      return {
        tiers: wire.tiers,
        rows: wire.games.map((game) => ({
          gameName: game.game_name,
          packageName: game.package_name,
          pricesRmByTierId: Object.fromEntries(game.prices.map((p) => [p.tier_id, p.price_sen / 100])),
        })),
      };
    },
    empty,
  );
}
