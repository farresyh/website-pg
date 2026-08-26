import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real contract for GET /api/track-order/{orderNumber}
 * (TrackOrderController) — deliberately narrow, customer-safe fields
 * only, see the controller's own doc comment for what's excluded.
 *
 * ADR-044: schema is the source of truth for the response shape below.
 */
const TrackedOrderSchema = z.object({
  order_number: z.string(),
  game: z.object({ name: z.string(), slug: z.string() }).nullable(),
  package_name: z.string().nullable(),
  player_id: z.string(),
  server_id: z.string().nullable(),
  final_amount: z.number(), // sen
  payment_status: z.enum(["pending", "paid", "failed"]),
  delivery_status: z.enum(["not_started", "processing", "delivered", "failed", "pending"]),
  created_at: z.string(),
});

export type TrackedOrder = z.infer<typeof TrackedOrderSchema>;

export async function trackOrder(orderNumber: string) {
  const path = `/api/track-order/${encodeURIComponent(orderNumber)}`;
  const raw = await apiFetch<unknown>(path);
  return parseResponse(TrackedOrderSchema, raw, "TrackedOrder", path);
}
