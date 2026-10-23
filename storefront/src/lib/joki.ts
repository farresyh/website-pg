import { z } from "zod";
import { ApiError, apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";
import { CredentialFieldWireSchema, toCredentialField, type CredentialField } from "@/lib/catalog";

/**
 * ADR-126 decision 12: the `/joki` page's data. `GET /api/joki/catalog`
 * answers only on a brand that sells joki (both switches, an owned
 * brand); anything else is "no joki here", which also hides the menu
 * item. Prices are whole ringgit, customer prices only — never the
 * payout share. A quote is `POST /api/joki/quote`, the same
 * JokiPricingService checkout charges with (never priced here).
 */
const TierSchema = z.object({
  id: z.number(),
  name: z.string(),
  kind: z.enum(["divisional", "mythic"]),
  divisions: z.number().nullable(),
  stars_per_division: z.number().nullable(),
  mythic_from_stars: z.number().nullable(),
  rate_rm: z.number(),
  express_rate_rm: z.number(),
  icon_url: z.string().nullable(),
});

const PositionSchema = z.object({
  tier_id: z.number(),
  tier_name: z.string(),
  division: z.number().nullable(),
  stars: z.number().nullable(),
  label: z.string(),
});

const CatalogSchema = z.object({
  game: z.object({ id: z.number(), slug: z.string(), name: z.string(), image_url: z.string().nullable() }),
  calculator_package_id: z.number(),
  tiers: z.array(TierSchema),
  routes: z.array(
    z.object({
      package_id: z.number(),
      name: z.string(),
      from: PositionSchema,
      to: PositionSchema,
      price_rm: z.number().nullable(),
      is_quotation: z.boolean(),
    }),
  ),
  settings: z.object({
    min_stars: z.number(),
    grading_price_rm: z.number(),
    grading_express_price_rm: z.number(),
    grading_lands_at_stars: z.number(),
    max_mythic_stars: z.number(),
    roles: z.array(z.string()),
    express_description: z.string().nullable(),
    terms_html: z.string().nullable(),
  }),
  credential_fields: z.array(CredentialFieldWireSchema),
  credential_consent_text: z.string().nullable(),
});

export type JokiTier = z.infer<typeof TierSchema>;
export type JokiPosition = z.infer<typeof PositionSchema>;
export type JokiRoute = z.infer<typeof CatalogSchema>["routes"][number];
export type JokiCatalog = Omit<z.infer<typeof CatalogSchema>, "credential_fields"> & { credentialFields: CredentialField[] };

/** A rank as the backend reads it; on the lowest Mythic tier `stars: null` is Grading. */
export interface JokiRank {
  tier_id: number;
  division: number | null;
  stars: number | null;
}

/** The joki block checkout, its total preview and the voucher preview take. */
export interface JokiSelection {
  from?: JokiRank;
  to?: JokiRank;
  is_express?: boolean;
  hero_request?: string;
  role?: string;
  play_window?: string;
}

const QuoteSchema = z.object({
  from: PositionSchema,
  to: PositionSchema,
  is_express: z.boolean(),
  lines: z.array(z.object({ label: z.string(), stars: z.number().nullable(), rate_rm: z.number().nullable(), amount_rm: z.number() })),
  total_rm: z.number(),
  cheaper_route: z.object({ package_id: z.number(), name: z.string(), price_rm: z.number() }).nullable(),
});

export type JokiQuote = z.infer<typeof QuoteSchema>;

/** Null when this brand doesn't sell joki (403/404) or the read failed: no page, no menu item. */
export async function getJokiCatalog(): Promise<JokiCatalog | null> {
  const path = "/api/joki/catalog";
  return safeRead(
    "getJokiCatalog",
    async () => {
      try {
        const { credential_fields, ...rest } = parseResponse(CatalogSchema, await apiFetch<unknown>(path, { next: catalogCache }), "JokiCatalog", path);
        return { ...rest, credentialFields: credential_fields.map(toCredentialField) };
      } catch (err) {
        if (err instanceof ApiError && (err.status === 403 || err.status === 404)) return null;
        throw err;
      }
    },
    null,
  );
}

export async function quoteJoki(packageId: number, selection: Pick<JokiSelection, "from" | "to" | "is_express">) {
  const path = "/api/joki/quote";
  const raw = await apiFetch<unknown>(path, { method: "POST", body: { package_id: packageId, joki: selection } });
  return parseResponse(QuoteSchema, raw, "JokiQuote", path);
}
