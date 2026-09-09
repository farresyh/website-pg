import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-053 — POST /api/orders/{orderNumber}/review (ReviewController).
 * order_number alone is the proof of ownership, same trust model as
 * track-order/checkout.
 */
const SubmitReviewResultSchema = z.object({
  id: z.number(),
  status: z.literal("pending"),
});

export type SubmitReviewResult = z.infer<typeof SubmitReviewResultSchema>;

export async function submitReview(orderNumber: string, rating: number, comment?: string) {
  const path = `/api/orders/${encodeURIComponent(orderNumber)}/review`;
  const raw = await apiFetch<unknown>(path, {
    method: "POST",
    body: { rating, comment: comment || undefined },
  });
  return parseResponse(SubmitReviewResultSchema, raw, "SubmitReviewResult", path);
}

const PublicReviewWireSchema = z.object({
  id: z.number(),
  name: z.string(),
  rating: z.number(),
  comment: z.string(),
  game_name: z.string().nullable().optional(),
  package_name: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
});

export type PublicReview = z.infer<typeof PublicReviewWireSchema>;

export async function listApprovedReviews(): Promise<PublicReview[]> {
  const path = "/api/catalog/reviews";
  return safeRead(
    "listApprovedReviews",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      return parseResponse(z.array(PublicReviewWireSchema), raw, "PublicReview[]", path);
    },
    [],
  );
}

const GameReviewItemWireSchema = z.object({
  id: z.number(),
  name: z.string(),
  rating: z.number(),
  comment: z.string(),
  package_name: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
});

export type GameReviewItem = z.infer<typeof GameReviewItemWireSchema>;

const GameReviewsWireSchema = z.object({
  average_rating: z.number(),
  review_count: z.number(),
  reviews: z.array(GameReviewItemWireSchema),
});

export type GameReviewsResult = z.infer<typeof GameReviewsWireSchema>;

export async function getGameReviews(slug: string): Promise<GameReviewsResult> {
  const path = `/api/catalog/games/${encodeURIComponent(slug)}/reviews`;
  return safeRead(
    `getGameReviews:${slug}`,
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      return parseResponse(GameReviewsWireSchema, raw, "GameReviewsResult", path);
    },
    { average_rating: 0, review_count: 0, reviews: [] },
  );
}
