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
  status: AffiliateStatus;
  notes: string | null;
  is_owned: boolean;
  is_primary: boolean;
  membership_enabled: boolean;
  /** ADR-126 decision 1: sells joki; owned brands only, and needs the global Joki switch. */
  joki_enabled: boolean;
  /** ADR-080 decision 4: active consumer memberships on this brand — the form warns before Membership is turned off while this is > 0. */
  active_membership_count: number;
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

export type AffiliateDomainStatus = "pending" | "active" | "failed" | "suspended";

export interface AffiliateDomainRow {
  id: number;
  hostname: string;
  status: AffiliateDomainStatus;
  is_primary: boolean;
  /** ADR-131: serves the store either way; takes CHIP payments only once approved. */
  payments_approved: boolean;
  payments_approved_at: string | null;
  provider: string;
  provider_managed: boolean;
  last_checked_at: string | null;
  verified_at: string | null;
  last_error: string | null;
}

export interface AffiliateDetail {
  affiliate: AffiliateRow;
  domains: AffiliateDomainRow[];
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
  notes: string | null;
  is_owned: boolean;
  membership_enabled: boolean;
  /** ADR-126 decision 1: sells joki; owned brands only, and needs the global Joki switch. */
  joki_enabled: boolean;
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

export function listAffiliates() {
  return apiFetch<{ affiliates: AffiliateRow[] }>("/api/affiliates");
}

export function getAffiliate(id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}`);
}

export function createAffiliate(values: CreateAffiliateValues) {
  return apiFetch<AffiliateDetail>("/api/affiliates", { method: "POST", body: values });
}

export function updateAffiliate(id: number, values: UpdateAffiliateValues) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}`, { method: "PUT", body: values });
}

export function updateAffiliateStatus(id: number, status: AffiliateStatus) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/status`, {
    method: "PATCH",
    body: { status },
  });
}

export function deleteAffiliate(id: number) {
  return apiFetch<{ message: string }>(`/api/affiliates/${id}`, { method: "DELETE" });
}

/** ADR-060 PR-5 — admin break-glass: force a provider re-check of one custom domain. */
export function recheckAffiliateDomain(id: number, domainId: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/domains/${domainId}/recheck`, {
    method: "POST",
  });
}

/** ADR-131 decision 21: approve (or revoke) one domain for CHIP payments. Behind step-up (api-client prompts and retries). */
export function approveAffiliateDomainPayments(id: number, domainId: number, approved: boolean) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/domains/${domainId}/payments-approval`, {
    method: "PUT",
    body: JSON.stringify({ approved }),
  });
}

/** ADR-060 PR-5 — admin break-glass: detach + remove one custom domain (abuse / affiliate request). */
export function removeAffiliateDomain(id: number, domainId: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/domains/${domainId}`, {
    method: "DELETE",
  });
}

export function assignAffiliateTier(id: number, tierId: number, note: string | null) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier`, {
    method: "POST",
    body: { tier_id: tierId, note },
  });
}

export function chargeAffiliateTierFee(id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier/charge`, { method: "POST" });
}

export function reactivateAffiliateSubscription(id: number) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/tier/reactivate`, { method: "POST" });
}

export function addAffiliateUser(id: number, values: { name: string; email: string }) {
  return apiFetch<AffiliateDetail>(`/api/affiliates/${id}/users`, { method: "POST", body: values });
}

export function resendAffiliateInvite(id: number, userId: number) {
  return apiFetch<{ message: string }>(`/api/affiliates/${id}/users/${userId}/resend-invite`, {
    method: "POST",
  });
}

export function impersonateAffiliate(id: number, reason: string | null) {
  return apiFetch<ImpersonationStartResult>(`/api/affiliates/${id}/impersonate`, {
    method: "POST",
    body: { reason },
  });
}

export function listImpersonationSessions() {
  return apiFetch<{ sessions: ImpersonationSessionRow[] }>("/api/affiliate-impersonation-sessions", {
  });
}

export function endImpersonationSession(sessionId: number) {
  return apiFetch<{ message: string }>(
    `/api/affiliate-impersonation-sessions/${sessionId}/end`,
    { method: "POST" },
  );
}

export function listAffiliateTiers() {
  return apiFetch<{ tiers: AffiliateTier[] }>("/api/affiliate-tiers");
}

export interface AffiliateTierValues {
  name: string;
  monthly_fee_sen: number;
  markup_percent: number;
  is_active: boolean;
  sort_order: number;
}

export function createAffiliateTier(values: AffiliateTierValues) {
  return apiFetch<AffiliateTier>("/api/affiliate-tiers", { method: "POST", body: values });
}

export function updateAffiliateTier(id: number, values: Partial<AffiliateTierValues>) {
  return apiFetch<AffiliateTier>(`/api/affiliate-tiers/${id}`, { method: "PUT", body: values });
}

export function deleteAffiliateTier(id: number) {
  return apiFetch<{ message: string }>(`/api/affiliate-tiers/${id}`, { method: "DELETE" });
}
