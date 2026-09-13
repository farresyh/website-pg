import { apiFetch } from "@/lib/api-client";
import type { Game } from "@/lib/games";
import type { Voucher } from "@/lib/vouchers";

/**
 * ORD-1..7 — list + detail + retry-delivery (ORD-7 / ADR-014) +
 * resend (ADR-017's package-swap counterpart) + issueVoucherFromOrder
 * (ORD-7's other resolution path). Export (ORD-5) is a later pass; see
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
  delivery_status: "not_started" | "processing" | "delivered" | "failed" | "needs_review" | "pending";
  payment_method: string | null;
  created_at: string;
  paid_at?: string | null;
  delivered_at?: string | null;
  game: { id: number; name: string } | null;
  package: { id: number; name: string; supplier_package_ref?: string; catalog_code?: string | null } | null;
  supplier_product_ref?: string | null;
  // Which brand's storefront this order belongs to (always set — every
  // order has an affiliate, ADR-061) and, for a wallet order placed via
  // the Reseller API/Bot (ADR-074/075), which Reseller account placed
  // it — mutually informative, never both meaningfully "the source" at
  // once: a wallet order's affiliate is always the platform's own
  // primary brand (ADR-073 decision 5), so wallet_reseller is the one
  // that actually answers "where did this order come from".
  affiliate: { id: number; business_name: string } | null;
  wallet_reseller: { id: number; business_name: string } | null;
}

/**
 * ADR-017 decision #4: one row per admin resend attempt — the
 * "Delivery Logs" history table renders this, most recent first.
 */
export interface OrderResendAttempt {
  id: number;
  package: { id: number; name: string; supplier_package_ref?: string } | null;
  cost_price_sen: number;
  standard_selling_price_sen: number;
  price_diff_sen: number;
  outcome: "success" | "failed";
  supplier_response?: Record<string, unknown> | null;
  note: string | null;
  triggered_by: string | null;
  created_at: string;
}

export interface OrderDetail extends OrderListItem {
  reference_number: string | null;
  customer_phone: string | null;
  cost_price: number;
  standard_selling_price: number;
  affiliate_markup_pct: string;
  wholesale_markup_pct?: string | null;
  voucher_discount: number | null;
  affiliate_profit: number;
  pricing_basis: "standard" | "member" | "reseller-wallet" | "affiliate";
  member_discount_percent: string | null;
  normal_selling_price: number | null;
  membership: { id: number; email: string; membership_plan: { name: string } } | null;
  payment_ref: string | null;
  supplier_ref: string | null;
  supplier_response: Record<string, unknown> | null;
  // Full Game shape (not the list endpoint's {id,name} projection) —
  // ADR-017's resend gate needs player_validator_enabled/profile_id.
  game: Game | null;
  supplier: { id: number; name: string } | null;
  // affiliate/wallet_reseller inherited from OrderListItem — non-null
  // wallet_reseller here is also the signal the order detail screen
  // uses to swap "Issue Voucher" for "Refund to Wallet" (ADR-073
  // decision 5/7), never both.
  // ADR-073 decision 7: computed server-side (not a stored column) —
  // true once a wallet_refund ledger entry exists for this order.
  wallet_refunded: boolean;
  resend_attempts: OrderResendAttempt[];
  // VCH-7: null until VoucherController::storeFromOrder() has been
  // called for this order — the unique index on vouchers.order_id
  // guarantees at most one.
  voucher: Voucher | null;
}

export interface OrderPage {
  data: OrderListItem[];
  current_page: number;
  last_page: number;
  total: number;
}

export type OrderStatusFilter =
  | "all"
  | "need_action"
  | "needs_review"
  | "pending_delivery"
  | "processing"
  | "completed"
  | "awaiting_payment"
  | "today";

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

/**
 * ADR-092: the six Orders KPI-card counts, one lean grouped-count read
 * decoupled from listOrders()'s paginated fetch — polled on an interval
 * by the page itself, never recomputed by search/filter changes.
 */
export interface OrderSummary {
  need_action: number;
  needs_review: number;
  processing: number;
  completed: number;
  today: number;
  all: number;
}

export function getOrderSummary(token: string) {
  return apiFetch<OrderSummary>("/api/orders/summary", { token });
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
  country_code?: string | null;
  redirect_game?: { slug: string; name: string } | null;
}

export function validatePlayerForResend(gameId: number, playerId: string, serverId?: string | null) {
  return apiFetch<ValidatePlayerForResendResult>(`/api/games/${gameId}/validate-player`, {
    method: "POST",
    body: { player_id: playerId, server_id: serverId || undefined },
  });
}

/**
 * ORD-7's other resolution path (ADR-004: retry-delivery or voucher,
 * never a cash refund). Backend computes and bounds the amount from
 * what the customer actually paid (`final_amount - transaction_fee`)
 * — this call never sends an amount. Backend also rejects (422)
 * unless delivery_status is already "failed" and no voucher has been
 * issued for this order yet (enforced by a real unique index, not
 * just this check).
 */
export function issueVoucherFromOrder(token: string, id: number, values: { reason?: string } = {}) {
  return apiFetch<Voucher>(`/api/orders/${id}/voucher`, { method: "POST", token, body: values });
}

/**
 * ADR-073 decision 7: the wallet-order counterpart to
 * issueVoucherFromOrder() above — replaces it entirely (never offered
 * alongside) whenever `wallet_reseller` is set. Credits the order's
 * `final_amount` back into that Reseller's wallet balance, no cash
 * ever leaves the platform.
 */
export function refundOrderToWallet(token: string, id: number) {
  return apiFetch<OrderDetail>(`/api/orders/${id}/refund-to-wallet`, { method: "POST", token });
}

/**
 * ADR-026 decision 4a — the one needs_review exit that isn't a retry.
 * `supplier_ref` is required: Gamevion's dashboard has no
 * reference-number search (confirmed live, ADR-026's Context), so the
 * admin must cross-reference by date range + player ID + game and
 * paste back the real invoice number they find there. Backend rejects
 * (422) unless delivery_status is already "needs_review".
 */
export function markOrderDelivered(token: string, id: number, values: { supplier_ref: string; note?: string }) {
  return apiFetch<OrderDetail>(`/api/orders/${id}/mark-delivered`, { method: "POST", token, body: values });
}
