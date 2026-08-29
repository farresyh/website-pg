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

export interface MembershipPricingPreviewRow {
  package_name: string;
  normal_price_sen: number;
  member_price_sen: number;
  margin_forgone_sen: number;
  savings_percent: number;
}

/**
 * Founder ask, 2026-08-29: preview an in-progress (unsaved) discount_percent
 * against real sample packages before "Save Tier" — backend computes this
 * via the same PricingService/MembershipPricingService CatalogController
 * itself uses, never re-derived here.
 */
export function previewMembershipPricing(token: string, discountPercent: number) {
  const query = new URLSearchParams({ discount_percent: String(discountPercent) });
  return apiFetch<MembershipPricingPreviewRow[]>(`/api/membership-plans/preview?${query}`, { token });
}
