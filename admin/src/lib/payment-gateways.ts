import { apiFetch } from "@/lib/api-client";

/**
 * ADR-110 PR-C — CHIP credential `.env`→DB migration, mirrors
 * `lib/suppliers.ts`'s own shape. `api_config` is never present on the
 * wire (backend model keeps it `$hidden`, same SUPP-5 discipline);
 * `has_credentials`/`configured_secret_keys` are the only signal this
 * screen gets about whether a secret is set.
 */
export interface PaymentGateway {
  gateway_key: string;
  has_credentials: boolean;
  configured_secret_keys: string[];
  visible_config: Record<string, string | null>;
  updated_at: string | null;
}

export type PaymentGatewayFieldType = "text" | "secret";

export interface PaymentGatewayField {
  key: string;
  label: string;
  type: PaymentGatewayFieldType;
  placeholder?: string;
}

/**
 * Mirror of the backend's `PaymentGatewayController::SECRET_KEYS` —
 * keep both in sync by hand, same convention `SUPPLIER_FIELD_DEFINITIONS`
 * already established. Only `chip` exists today; `PaymentGatewayFactory`
 * is the seam a future 2nd gateway would extend (ADR-022).
 */
export const PAYMENT_GATEWAY_FIELD_DEFINITIONS: Record<string, PaymentGatewayField[]> = {
  chip: [
    { key: "secret_key", label: "Secret Key", type: "secret" },
    { key: "brand_id", label: "Brand ID", type: "text", placeholder: "UUID, from portal.chip-in.asia/collect/developers/brands" },
    { key: "base_url", label: "Base URL", type: "text", placeholder: "https://gate.chip-in.asia/api/v1" },
  ],
};

export interface ConnectionProbe {
  connection_ok: boolean;
  error: string | null;
}

export function listPaymentGateways(token: string) {
  return apiFetch<PaymentGateway[]>("/api/middleware/payment-gateways", { token });
}

export function updatePaymentGateway(token: string, gatewayKey: string, apiConfig: Record<string, string>) {
  return apiFetch<PaymentGateway & { connection_probe: ConnectionProbe }>(
    `/api/middleware/payment-gateways/${gatewayKey}`,
    { method: "PUT", body: { api_config: apiConfig }, token },
  );
}
