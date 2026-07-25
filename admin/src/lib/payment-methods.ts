import { apiFetch } from "@/lib/api-client";

/**
 * SET-7/SET-11 — see backend/app/Http/Controllers/Middleware/PaymentMethodController.php
 * and the create_payment_methods_table migration's doc comment for
 * the full "why admin-curated, why /middleware" reasoning. `gateway`
 * is schema readiness for a future second payment gateway (2026-07-25
 * multi-gateway seam) — only 'xendit' is ever real today.
 */
export interface PaymentMethod {
  id: number;
  channel_code: string;
  label: string;
  category: string;
  gateway: string;
  is_active: boolean;
  percentage_rate: string; // decimal cast serializes as a string
  flat_fee_sen: number;
  requires_issuer: boolean;
  last_tested_at: string | null;
  last_test_result: string | null;
}

export function listPaymentMethods(token: string, params: { category?: string } = {}) {
  const query = new URLSearchParams();
  if (params.category) query.set("category", params.category);
  const qs = query.toString();

  return apiFetch<PaymentMethod[]>(`/api/middleware/payment-methods${qs ? `?${qs}` : ""}`, { token });
}

export function updatePaymentMethodStatus(token: string, id: number, isActive: boolean) {
  return apiFetch<PaymentMethod>(`/api/middleware/payment-methods/${id}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

export function updatePaymentMethodFee(token: string, id: number, percentageRate: number, flatFeeSen: number) {
  return apiFetch<PaymentMethod>(`/api/middleware/payment-methods/${id}/fee`, {
    method: "PATCH",
    token,
    body: { percentage_rate: percentageRate, flat_fee_sen: flatFeeSen },
  });
}

export function testPaymentMethod(token: string, id: number) {
  return apiFetch<PaymentMethod>(`/api/middleware/payment-methods/${id}/test`, {
    method: "POST",
    token,
  });
}
