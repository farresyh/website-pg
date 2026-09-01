import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

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
