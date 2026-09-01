import { apiFetch } from "@/lib/api-client";

/**
 * ADR-058 58b — admin Reseller Management (RES-1..6) + the
 * reseller_membership_tiers CRUD (ADR-056 decision 1). All endpoints are
 * super_admin-only on the backend.
 */

export type ResellerStatus = "active" | "inactive";
export type ResellerSubscriptionStatus = "active" | "grace" | "lapsed";

export interface ResellerSubscription {
  status: ResellerSubscriptionStatus;
  tier_id: number;
  tier_name: string | null;
  monthly_fee_sen: number | null;
  markup_percent: string | null;
  current_period_started_at: string | null;
  next_charge_at: string | null;
  grace_until: string | null;
}

export interface ResellerRow {
  id: number;
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  markup_pct: string;
  max_markup_pct: string | null;
  domains: string[];
  status: ResellerStatus;
  notes: string | null;
  is_owned: boolean;
  is_primary: boolean;
  membership_enabled: boolean;
  deleted_at: string | null;
  orders_count: number;
  earnings_balance_sen: number;
  subscription: ResellerSubscription | null;
}

export interface ResellerUserRow {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  last_login_at: string | null;
  invite_pending: boolean;
}

export interface ResellerTierChangeRow {
  id: number;
  from_tier: string | null;
  to_tier: string | null;
  admin: string | null;
  note: string | null;
  created_at: string;
}

export interface ResellerDetail {
  reseller: ResellerRow;
  users: ResellerUserRow[];
  tier_changes: ResellerTierChangeRow[];
}

export interface ResellerTier {
  id: number;
  name: string;
  monthly_fee_sen: number;
  markup_percent: string;
  is_active: boolean;
  sort_order: number;
  subscriptions_count: number;
}

export interface CreateResellerValues {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  markup_pct: number;
  max_markup_pct: number | null;
  domains: string[];
  notes: string | null;
  is_owned: boolean;
  membership_enabled: boolean;
  tier_id: number | null;
  user_name: string;
  user_email: string;
}

export type UpdateResellerValues = Omit<
  CreateResellerValues,
  "tier_id" | "user_name" | "user_email"
>;

export interface ImpersonationStartResult {
  session_id: number;
  token: string;
  acting_as: { id: number; name: string; email: string };
  portal_url: string;
  expires_at: string;
}

export interface ImpersonationSessionRow {
  id: number;
  reseller: string | null;
  reseller_id: number;
  admin: string | null;
  acting_as: string | null;
  reason: string | null;
  ip: string | null;
  started_at: string;
  ended_at: string | null;
  ended_reason: string | null;
  active: boolean;
}

export function listResellers(token: string) {
  return apiFetch<{ resellers: ResellerRow[] }>("/api/resellers", { token });
}

export function getReseller(token: string, id: number) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}`, { token });
}

export function createReseller(token: string, values: CreateResellerValues) {
  return apiFetch<ResellerDetail>("/api/resellers", { method: "POST", token, body: values });
}

export function updateReseller(token: string, id: number, values: UpdateResellerValues) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}`, { method: "PUT", token, body: values });
}

export function updateResellerStatus(token: string, id: number, status: ResellerStatus) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}/status`, {
    method: "PATCH",
    token,
    body: { status },
  });
}

export function deleteReseller(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/resellers/${id}`, { method: "DELETE", token });
}

export function assignResellerTier(token: string, id: number, tierId: number, note: string | null) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}/tier`, {
    method: "POST",
    token,
    body: { tier_id: tierId, note },
  });
}

export function chargeResellerTierFee(token: string, id: number) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}/tier/charge`, { method: "POST", token });
}

export function reactivateResellerSubscription(token: string, id: number) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}/tier/reactivate`, { method: "POST", token });
}

export function addResellerUser(token: string, id: number, values: { name: string; email: string }) {
  return apiFetch<ResellerDetail>(`/api/resellers/${id}/users`, { method: "POST", token, body: values });
}

export function resendResellerInvite(token: string, id: number, userId: number) {
  return apiFetch<{ message: string }>(`/api/resellers/${id}/users/${userId}/resend-invite`, {
    method: "POST",
    token,
  });
}

export function impersonateReseller(token: string, id: number, reason: string | null) {
  return apiFetch<ImpersonationStartResult>(`/api/resellers/${id}/impersonate`, {
    method: "POST",
    token,
    body: { reason },
  });
}

export function listImpersonationSessions(token: string) {
  return apiFetch<{ sessions: ImpersonationSessionRow[] }>("/api/reseller-impersonation-sessions", {
    token,
  });
}

export function endImpersonationSession(token: string, sessionId: number) {
  return apiFetch<{ message: string }>(
    `/api/reseller-impersonation-sessions/${sessionId}/end`,
    { method: "POST", token },
  );
}

export function listResellerTiers(token: string) {
  return apiFetch<{ tiers: ResellerTier[] }>("/api/reseller-tiers", { token });
}

export interface ResellerTierValues {
  name: string;
  monthly_fee_sen: number;
  markup_percent: number;
  is_active: boolean;
  sort_order: number;
}

export function createResellerTier(token: string, values: ResellerTierValues) {
  return apiFetch<ResellerTier>("/api/reseller-tiers", { method: "POST", token, body: values });
}

export function updateResellerTier(token: string, id: number, values: Partial<ResellerTierValues>) {
  return apiFetch<ResellerTier>(`/api/reseller-tiers/${id}`, { method: "PUT", token, body: values });
}

export function deleteResellerTier(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/reseller-tiers/${id}`, { method: "DELETE", token });
}
