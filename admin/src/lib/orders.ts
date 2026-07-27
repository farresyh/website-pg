import { apiFetch } from "@/lib/api-client";
import type { Game } from "@/lib/games";

/**
 * ORD-1..7 — list + detail + retry-delivery (ORD-7 / ADR-014) +
 * resend (ADR-017's package-swap counterpart). Voucher issuance (the
 * other ORD-7 action) and export (ORD-5) are a later pass; see
 * backend/app/Http/Controllers/Admin/OrderController.php.
 */
export interface OrderListItem {
  id: number;
  order_number: string;
  customer_email: string;
  player_id: string;
  server_id: string | null;
  selling_price: number;
  transaction_fee: number;
  final_amount: number;
  platform_profit: number;
  payment_status: "pending" | "paid" | "failed";
  delivery_status: "not_started" | "processing" | "delivered" | "failed";
  payment_method: string | null;
  created_at: string;
  game: { id: number; name: string } | null;
  package: { id: number; name: string } | null;
}

/**
 * ADR-017 decision #4: one row per admin resend attempt — the
 * "Delivery Logs" history table renders this, most recent first.
 */
export interface OrderResendAttempt {
  id: number;
  package: { id: number; name: string } | null;
  cost_price_sen: number;
  reseller_cost_price_sen: number;
  price_diff_sen: number;
  outcome: "success" | "failed";
  note: string | null;
  triggered_by: string | null;
  created_at: string;
}

export interface OrderDetail extends OrderListItem {
  reference_number: string | null;
  customer_phone: string | null;
  cost_price: number;
  reseller_cost_price: number;
  reseller_markup_pct: string;
  voucher_discount: number | null;
  reseller_profit: number;
  payment_ref: string | null;
  supplier_ref: string | null;
  supplier_response: Record<string, unknown> | null;
  // Full Game shape (not the list endpoint's {id,name} projection) —
  // ADR-017's resend gate needs player_validator_enabled/profile_id.
  game: Game | null;
  supplier: { id: number; name: string } | null;
  reseller: { id: number; business_name: string } | null;
  resend_attempts: OrderResendAttempt[];
}

export interface OrderPage {
  data: OrderListItem[];
  current_page: number;
  last_page: number;
  total: number;
}

export type OrderStatusFilter = "all" | "need_action" | "processing" | "completed" | "today";

export function listOrders(
  token: string,
  params: { status?: OrderStatusFilter; search?: string; page?: number } = {},
) {
  const query = new URLSearchParams();
  if (params.status && params.status !== "all") query.set("status", params.status);
  if (params.search) query.set("search", params.search);
  if (params.page) query.set("page", String(params.page));
  const qs = query.toString();

  return apiFetch<OrderPage>(`/api/orders${qs ? `?${qs}` : ""}`, { token });
}

export function getOrder(token: string, id: number) {
  return apiFetch<OrderDetail>(`/api/orders/${id}`, { token });
}

/**
 * ADR-017: queues ResendOrderDeliveryJob against a (possibly
 * different, same-game-only) package — backend rejects (422) unless
 * delivery_status is already "failed" and the package belongs to the
 * order's own game. The admin UI's package picker defaults to the
 * order's own `package_id`, so a same-package call here reproduces
 * ORD-7's original plain "Retry Delivery" behavior (the two buttons
 * were merged into one flow, founder feedback 2026-07-27) — the plain
 * `POST /api/orders/{order}/retry-delivery` endpoint (ADR-014) still
 * exists on the backend, just isn't called from this UI anymore.
 */
export function resendOrderDelivery(token: string, id: number, values: { package_id: number; note?: string }) {
  return apiFetch<{ message: string }>(`/api/orders/${id}/resend`, { method: "POST", token, body: values });
}

/**
 * ADR-017 decision #6: re-validates the order's own player_id/server_id
 * before the resend button is actionable, for games with a validator
 * profile assigned — reuses the same public, no-auth endpoint
 * (ADR-011) the storefront wizard already calls (storefront/src/lib/checkout.ts).
 * Writes a `player_validations` row the backend's own resend guard
 * then checks (same server-side enforcement CheckoutController uses).
 */
export interface ValidatePlayerForResendResult {
  status: "invalid" | "region_unknown" | "wrong_region" | "valid";
  nickname: string | null;
}

export function validatePlayerForResend(gameId: number, playerId: string, serverId?: string | null) {
  return apiFetch<ValidatePlayerForResendResult>(`/api/games/${gameId}/validate-player`, {
    method: "POST",
    body: { player_id: playerId, server_id: serverId || undefined },
  });
}
