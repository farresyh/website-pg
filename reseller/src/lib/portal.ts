import { apiFetch } from "@/lib/api-client";

/**
 * ADR-059 59a read layer. Mirrors
 * `backend/app/Http/Controllers/Reseller/*` + `ResellerEarningsService`.
 * This client only forwards the bearer token and renders whatever the
 * backend returns — no money math here (foundation-security.md).
 */

export type PaymentStatus = "pending" | "paid" | "failed";
export type DeliveryStatus =
  | "not_started"
  | "processing"
  | "delivered"
  | "failed"
  | "refunded";
export type SubscriptionStatus = "active" | "grace" | "lapsed";

export interface SubscriptionSnapshot {
  tier_name: string;
  status: SubscriptionStatus;
  monthly_fee_sen: number;
  next_charge_at: string | null;
  grace_until: string | null;
}

export interface DashboardStats {
  earnings_balance: number;
  today: { orders: number; sales: number };
  this_month: { orders: number; sales: number };
  subscription: SubscriptionSnapshot | null;
}

export interface OrderListItem {
  order_number: string;
  reference_number: string | null;
  game: { name: string; slug: string } | null;
  package_name: string | null;
  final_amount: number;
  reseller_profit: number;
  payment_status: PaymentStatus;
  delivery_status: DeliveryStatus;
  paid_at: string | null;
  created_at: string | null;
}

export interface OrderDetail extends OrderListItem {
  customer_email: string | null;
  customer_name: string | null;
  customer_phone: string | null;
  player_id: string | null;
  server_id: string | null;
  reseller_markup_pct: number;
  voucher_discount: number;
  transaction_fee: number;
  payment_method: string | null;
  delivered_at: string | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface LedgerEntry {
  id: number;
  type: string;
  amount: number;
  reference_type: string | null;
  reference_id: number | null;
  reason: string | null;
  created_at: string | null;
}

export interface EarningsResponse {
  balance: number;
  entries: Paginated<LedgerEntry>;
}

export interface SubscriptionResponse {
  subscription:
    | (SubscriptionSnapshot & {
        wholesale_markup_percent: number;
        current_period_started_at: string | null;
      })
    | null;
  charge_history: Array<{ id: number; amount: number; charged_at: string | null }>;
}

export interface OrderFilters {
  payment_status?: PaymentStatus;
  delivery_status?: DeliveryStatus;
  search?: string;
  page?: number;
}

export function getDashboard(token: string) {
  return apiFetch<DashboardStats>("/api/reseller/dashboard", { token });
}

export function listOrders(token: string, filters: OrderFilters = {}) {
  const params = new URLSearchParams();
  if (filters.payment_status) params.set("payment_status", filters.payment_status);
  if (filters.delivery_status) params.set("delivery_status", filters.delivery_status);
  if (filters.search) params.set("search", filters.search);
  if (filters.page) params.set("page", String(filters.page));
  const query = params.toString();

  return apiFetch<Paginated<OrderListItem>>(
    `/api/reseller/orders${query ? `?${query}` : ""}`,
    { token },
  );
}

export function getOrder(token: string, orderNumber: string) {
  return apiFetch<OrderDetail>(
    `/api/reseller/orders/${encodeURIComponent(orderNumber)}`,
    { token },
  );
}

export function getEarnings(token: string, page = 1) {
  return apiFetch<EarningsResponse>(`/api/reseller/earnings?page=${page}`, { token });
}

export function getSubscription(token: string) {
  return apiFetch<SubscriptionResponse>("/api/reseller/subscription", { token });
}

// --- 59c: Profile + Withdrawal + Impersonation ---

export interface ResellerProfile {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  bank_name: string | null;
  bank_account_no: string | null;
  bank_account_holder: string | null;
}

export type WithdrawalStatus = "pending" | "approved" | "rejected" | "completed";

export interface WithdrawalRow {
  id: number;
  amount: number;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  status: WithdrawalStatus;
  admin_note: string | null;
  created_at: string | null;
  processed_at: string | null;
}

export interface WithdrawalsResponse {
  balance: number;
  prefill: {
    bank_name: string | null;
    bank_account_no: string | null;
    bank_account_holder: string | null;
  };
  withdrawals: WithdrawalRow[];
}

export interface ImpersonationContext {
  session_id: number;
  admin_name: string | null;
  started_at: string | null;
}

export interface MeResponse {
  reseller_user: {
    id: number;
    reseller_id: number;
    name: string;
    email: string;
    last_login_at: string | null;
  };
  reseller: { id: number; business_name: string; status: string } | null;
  impersonation: ImpersonationContext | null;
}

export function getMe(token: string) {
  return apiFetch<MeResponse>("/api/reseller/me", { token });
}

export function getProfile(token: string) {
  return apiFetch<ResellerProfile>("/api/reseller/profile", { token });
}

export function updateProfile(
  token: string,
  body: Partial<
    Pick<
      ResellerProfile,
      | "contact_name"
      | "phone"
      | "bank_name"
      | "bank_account_no"
      | "bank_account_holder"
    >
  >,
) {
  return apiFetch<ResellerProfile>("/api/reseller/profile", {
    method: "PUT",
    token,
    body,
  });
}

export function getWithdrawals(token: string) {
  return apiFetch<WithdrawalsResponse>("/api/reseller/withdrawals", { token });
}

export function createWithdrawal(
  token: string,
  body: {
    amount: number;
    bank_name?: string;
    bank_account_no?: string;
    bank_account_holder?: string;
  },
) {
  return apiFetch<{ id: number; amount: number; status: WithdrawalStatus }>(
    "/api/reseller/withdrawals",
    { method: "POST", token, body },
  );
}

export function endImpersonation(token: string) {
  return apiFetch<{ message: string }>("/api/reseller/impersonation/end", {
    method: "POST",
    token,
  });
}
