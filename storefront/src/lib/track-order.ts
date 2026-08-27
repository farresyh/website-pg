import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real contract for GET /api/track-order/{orderNumber}
 * (TrackOrderController) — deliberately narrow, customer-safe fields
 * only, see the controller's own doc comment for what's excluded.
 *
 * ADR-044: schema is the source of truth for the response shape below.
 * ADR-047: also the contract for `OrderStatusUpdated`'s broadcast payload
 * (backend/app/Events/OrderStatusUpdated.php) — same narrow shape, one
 * source of truth for both the poll and the push path rather than two
 * schemas that could silently drift apart.
 */
export const TrackedOrderSchema = z.object({
  order_number: z.string(),
  game: z.object({ name: z.string(), slug: z.string() }).nullable(),
  package_name: z.string().nullable(),
  player_id: z.string(),
  server_id: z.string().nullable(),
  final_amount: z.number(), // sen
  payment_status: z.enum(["pending", "paid", "failed"]),
  // "needs_review" (ADR-026) was missing here — a real, pre-existing gap
  // this ADR-047 build found while reusing this schema for the broadcast
  // payload: any order reaching that state would have failed to parse on
  // both the poll and (without this fix) the new push path alike.
  delivery_status: z.enum(["not_started", "processing", "delivered", "failed", "pending", "needs_review"]),
  created_at: z.string(),
});

export type TrackedOrder = z.infer<typeof TrackedOrderSchema>;

export async function trackOrder(orderNumber: string) {
  const path = `/api/track-order/${encodeURIComponent(orderNumber)}`;
  const raw = await apiFetch<unknown>(path);
  return parseResponse(TrackedOrderSchema, raw, "TrackedOrder", path);
}
