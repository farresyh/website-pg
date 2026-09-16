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
  // 2026-09-15 bugfix: "pending" self-corrects to success/failed the
  // moment the real async outcome resolves — see
  // OrderFulfillmentService::resolvePendingResendAttempt().
  outcome: "success" | "failed" | "pending";
  supplier_response?: Record<string, unknown> | null;
  note: string | null;
  triggered_by: string | null;
  created_at: string;
}

/**
 * ADR-094 decision 12 (Phase 4): one row per real outbound supplier
 * call a combo order makes — empty for every ordinary single-supplier
 * order. `component_package`/`supplier` are always present once a leg
 * exists (both required at creation, decision 1).
 */
export interface OrderDeliveryLeg {
  id: number;
  leg_number: number;
  status: "not_started" | "processing" | "delivered" | "failed" | "needs_review" | "pending";
  supplier_reference: string | null;
  failure_reason: string | null;
  delivered_at: string | null;
  /** supplier_package_ref (2026-09-16 addendum): the actual SKU this leg submitted — distinct from `supplier_reference` above, which is the supplier's own transaction/response id, not the product code. */
  component_package: { id: number; name: string; denomination: number | null; supplier_package_ref: string | null } | null;
  supplier: { id: number; name: string } | null;
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
  // ADR-094 decision 12: empty for every ordinary order.
  delivery_legs: OrderDeliveryLeg[];
  // ADR-094 decision 9: true only for a combo order whose legs
  // genuinely split Delivered/Failed (not the ordinary leg-level-
  // ambiguity needs_review, which stays blocked exactly as ADR-026
  // decision 4c always intended) — the one carve-out that lets Issue
  // Voucher appear from a needs_review order.
  partial_combo_delivery: boolean;
  // Decision 9's prefill for that carve-out — the sum of every Failed
  // leg's own component price, admin-adjustable, never trusted as-is
  // (the backend re-derives and caps its own copy independently).
  suggested_voucher_amount: number | null;
  // ADR-026 addendum (2026-09-16) — computed server-side from the
  // persisted error_code (Digiflazz's own rc table / Gamevion's
  // duplicate_reference), never a second hand-copied rc list here.
  // Drives the Resend Delivery futility warning.
  delivery_retry_likely_futile: boolean;
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
 * ADR-014's plain retry (no package swap) — the counterpart to
 * resendOrderDelivery() above. ADR-094 decision 10: this is the ONLY
 * retry path that actually works for a combo order — resendOrderDelivery()
 * always rejects one (422, "use the ordinary Resend Delivery retry
 * instead"), since its package-swap tool assumes exactly one
 * supplier_product_ref to copy onto the Order, meaningless for a
 * multi-leg combo. The orders page routes combo orders (`delivery_legs.
 * length > 0`) here instead of opening ResendDeliveryModal.
 */
export function retryOrderDelivery(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/orders/${id}/retry-delivery`, { method: "POST", token });
}

/**
 * ORD-7's other resolution path (ADR-004: retry-delivery or voucher,
 * never a cash refund). For an ordinary failed order, the backend
 * computes and bounds the amount itself (`final_amount -
 * transaction_fee`) — `amount` here is ignored server-side. ADR-094
 * decision 9's carve-out is the one exception: a genuine partial-
 * delivery combo order (`OrderDetail.partial_combo_delivery`) has no
 * single correct auto-computed figure, so `amount` is required there
 * and admin-adjustable (still capped server-side at `final_amount`).
 * Backend also rejects (422) unless delivery_status is already
 * "failed" (or the partial-delivery carve-out applies) and no voucher
 * has been issued for this order yet (enforced by a real unique
 * index, not just this check).
 */
export function issueVoucherFromOrder(token: string, id: number, values: { reason?: string; amount?: number } = {}) {
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

/**
 * ADR-026 addendum (2026-09-16) — the other needs_review exit decision
 * 4c's own text always assumed existed: lands the order on plain
 * `failed`, unblocking the existing Issue Voucher action (a
 * deliberately separate second step, not collapsed into this one).
 * `note` is required — an admin's own "I've confirmed this genuinely
 * failed" claim, same discipline as markOrderDelivered() but for the
 * opposite outcome. Backend rejects (422) unless delivery_status is
 * already "needs_review", or for a genuine partial-combo-delivery
 * order (that case has its own custom-amount voucher path instead).
 */
export function confirmOrderDeliveryFailed(token: string, id: number, values: { note: string }) {
  return apiFetch<OrderDetail>(`/api/orders/${id}/confirm-failed`, { method: "POST", token, body: values });
}

/**
 * ADR-096 — a single supplier/gateway status-check response, shown
 * verbatim as a receipt of what the manual-poll button just did. `type`
 * distinguishes a plain order (one `outcome`/`data` pair) from a combo
 * order (one entry per still-Pending leg, decision 3 — one button
 * checks every leg in one call).
 */
export interface ManualCheckResult {
  type: "plain" | "combo";
  outcome?: string;
  applied?: boolean;
  data?: unknown;
  error_code?: string | null;
  error_message?: string | null;
  legs?: Array<{
    leg_number: number;
    outcome: string;
    applied: boolean;
    data?: unknown;
    error_code?: string | null;
    error_message?: string | null;
  }>;
}

export interface ManualCheckSupplierResponse {
  result: ManualCheckResult;
  delivery_status: OrderDetail["delivery_status"];
  delivered_at: string | null;
  supplier_ref: string | null;
}

export interface ManualCheckGatewayResponse {
  result: ManualCheckResult;
  payment_status: OrderDetail["payment_status"];
  paid_at: string | null;
}

/**
 * ADR-096 decision 5 — synchronous on purpose: the raw supplier
 * response is shown the moment this resolves, not via a queued job's
 * delayed result. A 422 (cooldown active) carries `retry_after_seconds`
 * on the thrown ApiError's `.payload`.
 */
export function checkOrderSupplier(token: string, id: number) {
  return apiFetch<ManualCheckSupplierResponse>(`/api/orders/${id}/check-supplier`, { method: "POST", token });
}

/** ADR-096 — the payment-side counterpart to checkOrderSupplier() above. */
export function checkOrderGateway(token: string, id: number) {
  return apiFetch<ManualCheckGatewayResponse>(`/api/orders/${id}/check-gateway`, { method: "POST", token });
}
