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
