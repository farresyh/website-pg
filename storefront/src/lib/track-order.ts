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
  // ADR-065 — masked server-side; the raw contact values never reach
  // this endpoint (see TrackOrderController's doc comment).
  customer_name_masked: z.string().nullable(),
  customer_email_masked: z.string().nullable(),
  customer_phone_masked: z.string().nullable(),
  // ADR-065 — customer-facing payment breakdown, all integer sen.
  payment_method: z.string().nullable(),
  selling_price: z.number(),
  voucher_discount: z.number(),
  transaction_fee: z.number(),
  final_amount: z.number(), // sen
  payment_status: z.enum(["pending", "paid", "failed"]),
  // "needs_review" (ADR-026) was missing here — a real, pre-existing gap
  // this ADR-047 build found while reusing this schema for the broadcast
  // payload: any order reaching that state would have failed to parse on
  // both the poll and (without this fix) the new push path alike.
  delivery_status: z.enum(["not_started", "processing", "delivered", "failed", "pending", "needs_review"]),
  created_at: z.string(),
  // ADR-053 decisions 3/4 — drives the review popup: shown once
  // delivered and this is still false, never re-shown once a review
  // exists. Server-truth, no client-side dismiss-forever state.
  has_review: z.boolean(),
});

export type TrackedOrder = z.infer<typeof TrackedOrderSchema>;

export async function trackOrder(orderNumber: string) {
  const path = `/api/track-order/${encodeURIComponent(orderNumber)}`;
  const raw = await apiFetch<unknown>(path);
  return parseResponse(TrackedOrderSchema, raw, "TrackedOrder", path);
}
