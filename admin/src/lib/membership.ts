import { apiFetch } from "@/lib/api-client";

/** ADR-027's 2026-08-29 addendum — /admin/membership's data layer. */

export interface MembershipPlan {
  id: number;
  name: string;
  fee_sen: number;
  quota_sen: number;
  discount_percent: string;
}

export type UpdateMembershipPlanValues = Pick<MembershipPlan, "name" | "fee_sen" | "quota_sen" | "discount_percent">;

export function getMembershipPlans(token: string) {
  return apiFetch<MembershipPlan[]>("/api/membership-plans", { token });
}

export function updateMembershipPlan(token: string, planId: number, values: UpdateMembershipPlanValues) {
  return apiFetch<MembershipPlan>(`/api/membership-plans/${planId}`, { method: "PUT", token, body: values });
}

export function updateMembershipEnabled(token: string, enabled: boolean) {
  return apiFetch<{ membership_enabled: boolean }>("/api/membership-plans/enabled", {
    method: "PATCH",
    token,
    body: { membership_enabled: enabled },
  });
}

/**
 * `package_name: null` (with every other field absent) is the "no active
 * packages to preview against" state — a consistent single shape, not a
 * bare JSON null (see MembershipPlanController::preview()'s own comment
 * on why).
 */
export type MembershipPricingPreview =
  | { package_name: null }
  | {
      package_name: string;
      package_markup_percent: number;
      discount_percent: number;
      effective_markup_percent: number;
      normal_price_sen: number;
      member_price_sen: number;
      margin_forgone_sen: number;
      savings_percent: number;
    };

/**
 * Founder ask, 2026-08-29: preview an in-progress (unsaved) discount_percent
 * against one real sample package before "Save Tier" — the markup%
 * breakdown (package markup -> discount -> effective markup) as well as
 * the final prices, so "kos yang ditanggung" is visible before saving.
 * Backend computes this via the same PricingService/MembershipPricingService
 * CatalogController itself uses, never re-derived here.
 */
export function previewMembershipPricing(token: string, discountPercent: number) {
  const query = new URLSearchParams({ discount_percent: String(discountPercent) });
  return apiFetch<MembershipPricingPreview>(`/api/membership-plans/preview?${query}`, { token });
}

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29) — the member registry + record-payment half of
 * /admin/membership. Effective status is computed server-side (a lapsed
 * member whose `expired` flip hasn't been swept yet still reads expired).
 */

export type MembershipStatus = "active" | "expired";

/** ADR-061 decision 5: an internal, membership-enabled brand a membership can belong to. */
export interface MembershipBrand {
  id: number;
  business_name: string;
}

export function listMembershipBrands(token: string) {
  return apiFetch<MembershipBrand[]>("/api/memberships/brands", { token });
}

export interface MembershipListItem {
  id: number;
  reseller_id: number;
  brand_name: string | null;
  email: string;
  plan_id: number;
  plan_name: string | null;
  status: MembershipStatus;
  cycle_started_at: string | null;
  expires_at: string | null;
  quota_remaining_sen: number;
  quota_used_sen: number;
  quota_total_sen: number;
  orders_count: number;
}

export interface MembershipPage {
  data: MembershipListItem[];
  current_page: number;
  last_page: number;
  total: number;
}

export type MembershipStatusFilter = "all" | "active" | "expired";

export function listMemberships(
  token: string,
  params: { status?: MembershipStatusFilter; planId?: number; search?: string; page?: number; resellerId?: number } = {},
) {
  const query = new URLSearchParams();
  if (params.status && params.status !== "all") query.set("status", params.status);
  if (params.planId) query.set("plan_id", String(params.planId));
  if (params.search) query.set("search", params.search);
  if (params.page) query.set("page", String(params.page));
  if (params.resellerId) query.set("reseller_id", String(params.resellerId));
  const qs = query.toString();

  return apiFetch<MembershipPage>(`/api/memberships${qs ? `?${qs}` : ""}`, { token });
}

export interface RecordMembershipPaymentValues {
  reseller_id: number;
  email: string;
  membership_plan_id: number;
  amount_sen: number;
  reason?: string | null;
  idempotency_key: string;
}

export function recordMembershipPayment(token: string, values: RecordMembershipPaymentValues) {
  return apiFetch<MembershipListItem>("/api/memberships/record-payment", {
    method: "POST",
    token,
    body: values,
  });
}

// --- ADR-068 PR-3: per-member detail (/admin/membership/[id]) ---

export function formatMemberRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export function formatMemberDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric" });
}

export interface MemberFeePayment {
  id: number;
  date: string | null;
  plan_name: string | null;
  amount_sen: number;
  /** "Admin — {name}" or "Self-serve". */
  source: string;
  reason: string | null;
  ledger_entry_id: number | null;
}

export type MemberCheckoutAttemptStatus = "pending" | "paid" | "failed" | "expired";

export interface MemberCheckoutAttempt {
  id: number;
  subscription_number: string;
  date: string | null;
  plan_name: string | null;
  status: MemberCheckoutAttemptStatus;
  fee_sen: number;
  total_charged_sen: number;
  channel_code: string;
}

export interface MembershipDetail {
  member: MembershipListItem & { member_since: string | null };
  fee_payments: MemberFeePayment[];
  checkout_attempts: MemberCheckoutAttempt[];
  orders_summary: {
    count: number;
    total_spent_sen: number;
    margin_forgone_sen: number;
  };
}

export function getMembershipDetail(token: string, id: number) {
  return apiFetch<MembershipDetail>(`/api/memberships/${id}`, { token });
}
