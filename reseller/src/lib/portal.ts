import { apiFetch } from "@/lib/api-client";

/**
 * ADR-059 59a read layer. Mirrors
 * `backend/app/Http/Controllers/Affiliate/*` + `AffiliateEarningsService`.
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

/**
 * ADR-072 decision 5 / PR-G: the reseller-portal (wallet) `Order`
 * screens reuse this exact same shape, over a narrower backend response
 * (`ResellerPortal\OrderController`) — every affiliate-only field
 * (`reference_number`, `affiliate_profit`, the customer/money detail
 * fields) is simply absent from that response, never present-but-zero.
 */
export interface OrderListItem {
  order_number: string;
  reference_number?: string | null;
  game: { name: string; slug: string } | null;
  package_name: string | null;
  final_amount: number;
  affiliate_profit?: number;
  payment_status: PaymentStatus;
  delivery_status: DeliveryStatus;
  paid_at?: string | null;
  created_at: string | null;
}

export interface OrderDetail extends OrderListItem {
  customer_email?: string | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  player_id: string | null;
  server_id: string | null;
  affiliate_markup_pct?: number;
  voucher_discount?: number;
  transaction_fee?: number;
  payment_method?: string | null;
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
  return apiFetch<DashboardStats>("/api/affiliate/dashboard", { token });
}

/**
 * ADR-072 decision 5 / PR-G: both account types have an Orders screen,
 * over two different (but response-compatible) backend endpoints —
 * `ownerType` picks which one. `search` has no reseller-portal
 * equivalent (the backend endpoint doesn't accept it) — silently
 * ignored rather than sent for a reseller session.
 */
export function listOrders(
  token: string,
  ownerType: "affiliate" | "reseller",
  filters: OrderFilters = {},
) {
  const params = new URLSearchParams();
  if (filters.payment_status) params.set("payment_status", filters.payment_status);
  if (filters.delivery_status) params.set("delivery_status", filters.delivery_status);
  if (filters.search && ownerType === "affiliate") params.set("search", filters.search);
  if (filters.page) params.set("page", String(filters.page));
  const query = params.toString();

  const base = ownerType === "affiliate" ? "/api/affiliate/orders" : "/api/reseller-portal/orders";

  return apiFetch<Paginated<OrderListItem>>(`${base}${query ? `?${query}` : ""}`, { token });
}

export function getOrder(token: string, ownerType: "affiliate" | "reseller", orderNumber: string) {
  const base = ownerType === "affiliate" ? "/api/affiliate/orders" : "/api/reseller-portal/orders";

  return apiFetch<OrderDetail>(`${base}/${encodeURIComponent(orderNumber)}`, { token });
}

export function getEarnings(token: string, page = 1) {
  return apiFetch<EarningsResponse>(`/api/affiliate/earnings?page=${page}`, { token });
}

export function getSubscription(token: string) {
  return apiFetch<SubscriptionResponse>("/api/affiliate/subscription", { token });
}

// --- 59c: Profile + Withdrawal + Impersonation ---

export interface AffiliateProfile {
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
  affiliate_user: {
    id: number;
    owner_type: "affiliate" | "reseller";
    owner_id: number;
    name: string;
    email: string;
    last_login_at: string | null;
  };
  affiliate: { id: number; business_name: string; status: string } | null;
  reseller: { id: number; business_name: string; is_active: boolean } | null;
  impersonation: ImpersonationContext | null;
}

export function getMe(token: string) {
  return apiFetch<MeResponse>("/api/affiliate/me", { token });
}

export function getProfile(token: string) {
  return apiFetch<AffiliateProfile>("/api/affiliate/profile", { token });
}

export function updateProfile(
  token: string,
  body: Partial<
    Pick<
      AffiliateProfile,
      | "contact_name"
      | "phone"
      | "bank_name"
      | "bank_account_no"
      | "bank_account_holder"
    >
  >,
) {
  return apiFetch<AffiliateProfile>("/api/affiliate/profile", {
    method: "PUT",
    token,
    body,
  });
}

export function getWithdrawals(token: string) {
  return apiFetch<WithdrawalsResponse>("/api/affiliate/withdrawals", { token });
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
    "/api/affiliate/withdrawals",
    { method: "POST", token, body },
  );
}

export function endImpersonation(token: string) {
  return apiFetch<{ message: string }>("/api/affiliate/impersonation/end", {
    method: "POST",
    token,
  });
}
