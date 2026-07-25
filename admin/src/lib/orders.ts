import { apiFetch } from "@/lib/api-client";

/**
 * ORD-1..6 — read-only for this pass (list + detail). Resolve
 * actions (ORD-7: retry-delivery/voucher) and export (ORD-5) are a
 * later pass; see backend/app/Http/Controllers/Admin/OrderController.php.
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
  supplier: { id: number; name: string } | null;
  reseller: { id: number; business_name: string } | null;
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
