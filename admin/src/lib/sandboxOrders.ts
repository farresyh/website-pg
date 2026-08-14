import { apiFetch } from "@/lib/api-client";
import type { OrderDetail, OrderPage } from "@/lib/orders";

/**
 * ADR-018 — a middleware-only sandbox for exercising the real Order
 * lifecycle without touching real data or real money. Every response
 * shape here mirrors lib/orders.ts exactly (same backend model, same
 * JSON resource) — the only new field is `is_test`, always true for
 * anything this file talks to. See
 * backend/app/Http/Controllers/Middleware/SandboxOrderController.php.
 */
export interface SandboxOrderDetail extends OrderDetail {
  is_test: boolean;
}

export function listSandboxOrders(token: string, params: { search?: string; page?: number } = {}) {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.page) query.set("page", String(params.page));
  const qs = query.toString();

  return apiFetch<OrderPage>(`/api/middleware/sandbox${qs ? `?${qs}` : ""}`, { token });
}

export function getSandboxOrder(token: string, id: number) {
  return apiFetch<SandboxOrderDetail>(`/api/middleware/sandbox/${id}`, { token });
}

/**
 * ADR-018 decision #3: customer/player fields are all optional —
 * SandboxOrderController defaults any omitted one to a fixed sandbox
 * placeholder. game_id/package_id are the only real requirement (the
 * package must be active and belong to the selected game).
 */
export interface CreateSandboxOrderValues {
  game_id: number;
  package_id: number;
  customer_email?: string;
  customer_name?: string;
  customer_phone?: string;
  player_id?: string;
  server_id?: string;
}

export function createSandboxOrder(token: string, values: CreateSandboxOrderValues) {
  return apiFetch<SandboxOrderDetail>("/api/middleware/sandbox", { method: "POST", token, body: values });
}

/**
 * ADR-018 decision #5: `simulate_success` drives which outcome
 * FakeSupplierAdapter returns — error_code/error_message are only
 * meaningful when it's false, and default server-side when omitted.
 * Runs synchronously (no queue) — the response already reflects the
 * outcome, no polling needed.
 */
export interface ResendSandboxOrderDeliveryValues {
  package_id: number;
  note?: string;
  simulate_success: boolean;
  error_code?: string;
  error_message?: string;
}

export function resendSandboxOrderDelivery(token: string, id: number, values: ResendSandboxOrderDeliveryValues) {
  return apiFetch<SandboxOrderDetail>(`/api/middleware/sandbox/${id}/resend`, { method: "POST", token, body: values });
}

/**
 * ADR-026 decision 4a's sandbox counterpart — same
 * OrderFulfillmentService::markDeliveredManually() a real needs_review
 * order uses, so no LedgerEntry is written even though the response
 * shape is identical to a real one.
 */
export function markSandboxOrderDelivered(token: string, id: number, values: { supplier_ref: string; note?: string }) {
  return apiFetch<SandboxOrderDetail>(`/api/middleware/sandbox/${id}/mark-delivered`, { method: "POST", token, body: values });
}

export function deleteSandboxOrder(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/middleware/sandbox/${id}`, { method: "DELETE", token });
}

export function deleteAllSandboxOrders(token: string) {
  return apiFetch<{ message: string }>("/api/middleware/sandbox", { method: "DELETE", token });
}
