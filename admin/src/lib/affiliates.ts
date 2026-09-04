import { apiFetch } from "@/lib/api-client";

/**
 * ADR-058 58b — admin Affiliate Management (RES-1..6) + the
 * affiliate_membership_tiers CRUD (ADR-056 decision 1). All endpoints are
 * super_admin-only on the backend.
 */

export type AffiliateStatus = "active" | "inactive";
export type AffiliateSubscriptionStatus = "active" | "grace" | "lapsed";

export interface AffiliateSubscription {
  status: AffiliateSubscriptionStatus;
  tier_id: number;
  tier_name: string | null;
  monthly_fee_sen: number | null;
  markup_percent: string | null;
  current_period_started_at: string | null;
  next_charge_at: string | null;
  grace_until: string | null;
}

export interface AffiliateRow {
  id: number;
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  markup_pct: string;
  max_markup_pct: string | null;
  domains: string[];
  status: AffiliateStatus;
  notes: string | null;
  is_owned: boolean;
  is_primary: boolean;
  membership_enabled: boolean;
  deleted_at: string | null;
  orders_count: number;
  earnings_balance_sen: number;
  subscription: AffiliateSubscription | null;
}

export interface AffiliateUserRow {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  last_login_at: string | null;
  invite_pending: boolean;
}

export interface AffiliateTierChangeRow {
  id: number;
  from_tier: string | null;
  to_tier: string | null;
  admin: string | null;
  note: string | null;
  created_at: string;
}

export interface AffiliateDetail {
  affiliate: AffiliateRow;
  users: AffiliateUserRow[];
  tier_changes: AffiliateTierChangeRow[];
}

export interface AffiliateTier {
  id: number;
  name: string;
  monthly_fee_sen: number;
  markup_percent: string;
  is_active: boolean;
  sort_order: number;
  subscriptions_count: number;
}

export interface CreateAffiliateValues {
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

export type UpdateAffiliateValues = Omit<
  CreateAffiliateValues,
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
  affiliate: string | null;
  affiliate_id: number;
  admin: string | null;
  acting_as: string | null;
  reason: string | null;
  ip: string | null;
  started_at: string;
  ended_at: string | null;
  ended_reason: string | null;
  active: boolean;
}

export function listAffiliates(token: string) {
  return apiFetch<{ affiliates: AffiliateRow[] }>("/api/affiliates", { token });
}

export function getAffiliate(token: string, id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}`, { token });
}

export function createAffiliate(token: string, values: CreateAffiliateValues) {
  return apiFetch<AffiliateDetail>("/api/affiliates", { method: "POST", token, body: values });
}

export function updateAffiliate(token: string, id: number, values: UpdateAffiliateValues) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}`, { method: "PUT", token, body: values });
}

export function updateAffiliateStatus(token: string, id: number, status: AffiliateStatus) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/status`, {
    method: "PATCH",
    token,
    body: { status },
  });
}

export function deleteAffiliate(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/affiliates/${id}`, { method: "DELETE", token });
}

export function assignAffiliateTier(token: string, id: number, tierId: number, note: string | null) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier`, {
    method: "POST",
    token,
    body: { tier_id: tierId, note },
  });
}

export function chargeAffiliateTierFee(token: string, id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier/charge`, { method: "POST", token });
}

export function reactivateAffiliateSubscription(token: string, id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier/reactivate`, { method: "POST", token });
}

export function addAffiliateUser(token: string, id: number, values: { name: string; email: string }) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/users`, { method: "POST", token, body: values });
}

export function resendAffiliateInvite(token: string, id: number, userId: number) {
  return apiFetch<{ message: string }>(`/api/affiliates/${id}/users/${userId}/resend-invite`, {
    method: "POST",
    token,
  });
}

export function impersonateAffiliate(token: string, id: number, reason: string | null) {
  return apiFetch<ImpersonationStartResult>(`/api/affiliates/${id}/impersonate`, {
    method: "POST",
    token,
    body: { reason },
  });
}

export function listImpersonationSessions(token: string) {
  return apiFetch<{ sessions: ImpersonationSessionRow[] }>("/api/affiliate-impersonation-sessions", {
    token,
  });
}

export function endImpersonationSession(token: string, sessionId: number) {
  return apiFetch<{ message: string }>(
    `/api/affiliate-impersonation-sessions/${sessionId}/end`,
    { method: "POST", token },
  );
}

export function listAffiliateTiers(token: string) {
  return apiFetch<{ tiers: AffiliateTier[] }>("/api/affiliate-tiers", { token });
}

export interface AffiliateTierValues {
  name: string;
  monthly_fee_sen: number;
  markup_percent: number;
  is_active: boolean;
  sort_order: number;
}

export function createAffiliateTier(token: string, values: AffiliateTierValues) {
  return apiFetch<AffiliateTier>("/api/affiliate-tiers", { method: "POST", token, body: values });
}

export function updateAffiliateTier(token: string, id: number, values: Partial<AffiliateTierValues>) {
  return apiFetch<AffiliateTier>(`/api/affiliate-tiers/${id}`, { method: "PUT", token, body: values });
}

export function deleteAffiliateTier(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/affiliate-tiers/${id}`, { method: "DELETE", token });
}
