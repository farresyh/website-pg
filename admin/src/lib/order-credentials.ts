import { apiFetch } from "@/lib/api-client";

/**
 * ADR-124: a customer's game login on an order, as every admin who can
 * open the order sees it. Secret values are never here; a super admin
 * fetches one at a time with revealOrderCredential (MFA step-up, logged).
 */
export interface CustomerLogin {
  fields: { key: string; label: string; value: string }[];
  secret_fields: { key: string; label: string }[];
  secrets_set_at: string;
  purged_at: string | null;
  consented_at: string;
  consent_text: string;
}

export function revealOrderCredential(orderId: number, field: string) {
  return apiFetch<{ field: string; value: string }>(`/api/orders/${orderId}/credentials/reveal`, {
    method: "POST",
    body: { field },
  });
}

/** Allowed while the order is open; restarts the wipe ceiling (ADR-124 2026-10-18 addendum). */
export function replaceOrderCredential(orderId: number, field: string, value: string) {
  return apiFetch<CustomerLogin>(`/api/orders/${orderId}/credentials`, {
    method: "PUT",
    body: { field, value },
  });
}
