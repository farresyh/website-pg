import { apiFetch } from "@/lib/api-client";

/**
 * ADR-053 (REV-1..5) — mirrors backend/app/Models/Review.php.
 * `game`/`package_name` come from the eager-loaded `order` relation,
 * never duplicated columns on Review itself.
 */
export type ReviewStatus = "pending" | "approved" | "rejected";

export interface Review {
  id: number;
  order_id: number;
  rating: number;
  comment: string | null;
  status: ReviewStatus;
  created_at: string;
  order: {
    order_number: string;
    customer_email: string;
    game: { id: number; name: string } | null;
    package: { id: number; name: string } | null;
  };
}

export interface ReviewPage {
  data: Review[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface ReviewStats {
  pending: number;
  approved: number;
  rejected: number;
  total: number;
  average_rating: number;
}

export interface ReviewIndexResponse {
  stats: ReviewStats;
  reviews: ReviewPage;
}

export interface ReviewFilters {
  status?: ReviewStatus;
  rating?: number;
  game_id?: number;
  with_comment_only?: boolean;
  page?: number;
  per_page?: number;
}

export function listReviews(token: string, filters: ReviewFilters = {}) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }

  const query = params.toString();

  return apiFetch<ReviewIndexResponse>(`/api/reviews${query ? `?${query}` : ""}`, { token });
}

export function approveReview(token: string, id: number) {
  return apiFetch<Review>(`/api/reviews/${id}/approve`, { method: "PATCH", token });
}

export function rejectReview(token: string, id: number) {
  return apiFetch<Review>(`/api/reviews/${id}/reject`, { method: "PATCH", token });
}

export function bulkApproveReviews(token: string) {
  return apiFetch<{ approved_count: number }>("/api/reviews/bulk-approve", { method: "POST", token });
}
