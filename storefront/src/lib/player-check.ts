import { z } from "zod";
import { ApiError, apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-123: the public Game ID Checker. A family is a validator profile
 * (Mobile Legends, Free Fire, …); the answer names the store we sell for
 * that account. Listing is a cached catalog read; a check is a POST with
 * a Turnstile token (PlayerCheckController).
 */

const PlayerCheckFamilySchema = z.object({
  key: z.string(),
  slug: z.string(),
  name: z.string(),
  image_url: z.string().nullable(),
  player_id_format: z.enum(["numeric", "text"]),
  requires_server_id: z.boolean(),
});

export type PlayerCheckFamily = z.infer<typeof PlayerCheckFamilySchema>;

const FamiliesSchema = z.object({ indexable: z.boolean(), families: z.array(PlayerCheckFamilySchema) });
const FamilySchema = z.object({ indexable: z.boolean(), family: PlayerCheckFamilySchema });

export interface PlayerCheckFamilies {
  /** ADR-123 decision 10: only the primary brand's checker pages are indexed. */
  indexable: boolean;
  families: PlayerCheckFamily[];
}

export async function listPlayerCheckFamilies(): Promise<PlayerCheckFamilies> {
  const path = "/api/catalog/player-check/families";
  return safeRead(
    "listPlayerCheckFamilies",
    async () => parseResponse(FamiliesSchema, await apiFetch<unknown>(path, { next: catalogCache }), "PlayerCheckFamilies", path),
    { indexable: false, families: [] },
  );
}

/** Null when this brand doesn't list the family (unknown slug, no active store). */
export async function getPlayerCheckFamily(slug: string): Promise<{ indexable: boolean; family: PlayerCheckFamily } | null> {
  const path = `/api/catalog/player-check/families/${encodeURIComponent(slug)}`;
  return safeRead(
    "getPlayerCheckFamily",
    async () => {
      try {
        return parseResponse(FamilySchema, await apiFetch<unknown>(path, { next: catalogCache }), "PlayerCheckFamily", path);
      } catch (err) {
        // An unknown or unlisted slug is an expected 404, not a failure.
        if (err instanceof ApiError && err.status === 404) return null;
        throw err;
      }
    },
    null,
  );
}

const PlayerCheckResultSchema = z.object({
  status: z.enum(["found", "unsupported_region", "unavailable_here", "not_found", "busy"]),
  nickname: z.string().nullable(),
  zone: z.string().nullable(),
  stores: z.array(z.object({ slug: z.string(), name: z.string() })),
  player_id: z.string(),
  server_id: z.string().nullable(),
});

export type PlayerCheckResult = z.infer<typeof PlayerCheckResultSchema>;

export async function checkPlayer(slug: string, playerId: string, serverId: string | undefined, turnstileToken: string) {
  const path = `/api/player-check/${encodeURIComponent(slug)}`;
  const raw = await apiFetch<unknown>(path, {
    method: "POST",
    body: { player_id: playerId, server_id: serverId || undefined, turnstile_token: turnstileToken },
  });
  return parseResponse(PlayerCheckResultSchema, raw, "PlayerCheckResult", path);
}

/** "Top up now": the order page with the ID prefilled, validated there by the customer (ADR-123 decision 5). */
export function orderHrefWithPrefill(storeSlug: string, playerId: string, serverId: string | null): string {
  const params = new URLSearchParams({ player_id: playerId });
  if (serverId) params.set("server_id", serverId);
  return `/order/${storeSlug}?${params.toString()}`;
}
