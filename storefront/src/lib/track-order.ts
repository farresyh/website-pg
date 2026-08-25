import { apiFetch } from "@/lib/api-client";

/**
 * Real contract for GET /api/track-order/{orderNumber}
 * (TrackOrderController) — deliberately narrow, customer-safe fields
 * only, see the controller's own doc comment for what's excluded.
 */
export interface TrackedOrder {
  order_number: string;
  game: { name: string; slug: string } | null;
  package_name: string | null;
  player_id: string;
  server_id: string | null;
  final_amount: number; // sen
  payment_status: "pending" | "paid" | "failed";
  delivery_status: "not_started" | "processing" | "delivered" | "failed" | "pending";
  created_at: string;
}

export function trackOrder(orderNumber: string) {
  return apiFetch<TrackedOrder>(`/api/track-order/${encodeURIComponent(orderNumber)}`);
}
